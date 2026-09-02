<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Blobs\Blob;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use StreetMesh\Server\Protocol\Permissions\Spent;
use StreetMesh\Server\Protocol\Records\Record;

/**
 * The end of the exercise: a venue writes a record into somebody else's store.
 *
 * Everything before this was arranging permission. This is spending it — and
 * the record that results belongs to the resident rather than to the venue that
 * wrote it, which is the whole claim the project makes.
 */
class RepoTest extends TestCase
{
    private const VENUE = 'https://games.test/client-metadata.json';

    private const CHESS = 'com.streetmesh.games.chess';

    private const ALICE = 'did:plc:alice';

    private const VENUE_DID = 'did:web:games.test';

    private P256 $venueKey;

    private P256 $sessionKey;

    private FakeNetwork $network;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venueKey = P256::generate();
        $this->sessionKey = P256::generate();

        $this->network = (new FakeNetwork)
            ->serve(self::VENUE, [
                'client_id' => self::VENUE,
                'redirect_uris' => ['https://games.test/connect/callback'],
                'jwks_uri' => 'https://games.test/jwks.json',
            ])
            ->serve('https://games.test/jwks.json', ClientMetadata::keySet([
                'atproto' => Jwk::forP256($this->venueKey),
            ]))
            /*
             * The venue's identity, which is a different thing from its OAuth
             * client keys: this is what it signs *statements* with, and what a
             * stranger resolves years later to check one.
             */
            ->serve('https://games.test/.well-known/did.json', [
                'id' => self::VENUE_DID,
                'verificationMethod' => [[
                    'id' => self::VENUE_DID.'#atproto',
                    'type' => 'Multikey',
                    'controller' => self::VENUE_DID,
                    'publicKeyMultibase' => $this->venueKey->multikey(),
                ]],
            ]);

        $this->app->instance(Network::class, $this->network);

        $this->token = $this->grant('atproto '.Scope::forRepo([self::CHESS], [Scope::CREATE]));
    }

    /**
     * A permission, arranged the long way, because a token conjured directly
     * would not prove the thing being tested.
     */
    private function grant(string $scope): string
    {
        $permissions = new Permissions($this->app->make(Network::class), $this->app->make(Spent::class));
        $issuer = 'https://games.test';
        $pkce = Pkce::generate();
        $thumbprint = Jwk::forP256($this->sessionKey)->thumbprint();

        $pushed = $permissions->push([
            'client_id' => self::VENUE,
            'redirect_uri' => 'https://games.test/connect/callback',
            'scope' => $scope,
            'code_challenge' => $pkce->challenge(),
            'code_challenge_method' => 'S256',
            'client_assertion' => ClientAssertion::for(self::VENUE, $issuer, $this->venueKey),
        ], $issuer, $thumbprint);

        $code = $permissions->approve($permissions->pending((string) $pushed->request_uri), self::ALICE);

        return $permissions->redeem([
            'client_id' => self::VENUE,
            'code' => $code,
            'code_verifier' => $pkce->verifier,
            'client_assertion' => ClientAssertion::for(self::VENUE, $issuer, $this->venueKey),
        ], $issuer, $thumbprint)['access'];
    }

    /**
     * What a venue signs, as it would sign it.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function attested(array $claims, ?P256 $signedWith = null): array
    {
        return ['attestation' => $this->app->make(Attestations::class)->issue(
            $claims,
            $signedWith ?? $this->venueKey,
            self::VENUE_DID.'#atproto',
        )];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function write(array $body, ?string $token = null, ?P256 $key = null): TestResponse
    {
        $token ??= $this->token;
        $key ??= $this->sessionKey;
        $url = url('/xrpc/com.atproto.repo.createRecord');

        return $this->postJson($url, $body, [
            'Authorization' => 'DPoP '.$token,
            'DPoP' => Dpop::proof($key, 'POST', $url, accessToken: $token),
        ]);
    }

    public function test_a_venue_writes_a_finished_game_into_the_players_own_store(): void
    {
        $response = $this->write([
            'collection' => self::CHESS,
            'record' => $this->attested(['result' => 'win', 'seat' => 'white', 'pgn' => '']),
        ]);

        $response->assertCreated();

        $record = Record::query()->firstOrFail();

        $this->assertSame(self::ALICE, $record->did, 'the record belongs to the player, not the venue');
        $this->assertSame(self::CHESS, $record->collection);
        $this->assertSame('win', $record->value['result']);
        $this->assertSame($record->cid, $response->json('cid'));
        $this->assertStringStartsWith('at://'.self::ALICE, (string) $response->json('uri'));
    }

    /**
     * An empty string inside a signed record used to become null on the way in,
     * and the signature would then fail to verify for a record that was never
     * wrong. Worth a test on the one route where a stranger posts a record.
     */
    public function test_an_empty_field_survives_being_posted(): void
    {
        $this->write([
            'collection' => self::CHESS,
            'record' => $this->attested(['result' => 'win', 'pgn' => '']),
        ])->assertCreated();

        $this->assertSame('', Record::query()->firstOrFail()->value['pgn']);
    }

    /**
     * The record keeps the signature, not only what it said.
     *
     * This is the difference between a record somebody holds and a record they
     * merely received. The decoded fields are a convenience; the compact form
     * is the part a stranger can check years from now, against a key the venue
     * published, whether or not the venue still exists.
     */
    public function test_the_record_carries_the_signature_that_can_be_checked_later(): void
    {
        $this->write([
            'collection' => self::CHESS,
            'record' => $this->attested(['result' => 'win']),
        ])->assertCreated();

        $stored = Record::query()->firstOrFail()->value;

        $this->assertSame(self::VENUE_DID, $stored['issuer']);
        $this->assertArrayHasKey('receivedAt', $stored);

        // And it still verifies on its own, with nothing but the document.
        $checked = $this->app->make(Attestations::class)->verify($stored['attestation']);

        $this->assertSame('win', $checked->claim('result'));
        $this->assertSame(self::VENUE_DID, $checked->issuer);
    }

    /**
     * An unsigned record is worth what the sender's continued existence is
     * worth, which is the thing this project exists not to accept.
     */
    public function test_a_record_with_no_signature_is_refused(): void
    {
        $this->write([
            'collection' => self::CHESS,
            'record' => ['result' => 'win'],
        ])->assertStatus(400);

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * Signed by a key the venue does not publish, which is what a forgery looks
     * like from here.
     */
    public function test_a_record_signed_by_a_key_the_venue_does_not_publish_is_refused(): void
    {
        $this->write([
            'collection' => self::CHESS,
            'record' => $this->attested(['result' => 'win'], signedWith: P256::generate()),
        ])->assertStatus(400);

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * Genuine, and still not this venue's to deliver.
     *
     * A venue with permission to add records could otherwise relay somebody
     * else's real signed statement into a resident's store — not a forgery,
     * since it verifies, but not what that resident agreed to receive either.
     */
    public function test_a_statement_signed_by_somebody_else_is_refused(): void
    {
        $stranger = P256::generate();
        $strangerDid = 'did:web:elsewhere.test';

        $this->network->serve('https://elsewhere.test/.well-known/did.json', [
            'id' => $strangerDid,
            'verificationMethod' => [[
                'id' => $strangerDid.'#atproto',
                'type' => 'Multikey',
                'controller' => $strangerDid,
                'publicKeyMultibase' => $stranger->multikey(),
            ]],
        ]);

        $relayed = ['attestation' => $this->app->make(Attestations::class)->issue(
            ['result' => 'win'],
            $stranger,
            $strangerDid.'#atproto',
        )];

        $this->write(['collection' => self::CHESS, 'record' => $relayed])->assertStatus(400);

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * The scope decides, not the venue's identity and not this server's opinion
     * of it.
     */
    public function test_a_collection_the_permission_does_not_cover_is_refused(): void
    {
        $this->write([
            'collection' => 'com.streetmesh.messages.direct',
            'record' => $this->attested(['body' => 'hello']),
        ])->assertForbidden()->assertJsonPath('error', 'insufficient_scope');

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * What the resident was actually shown, kept as it was asked for — a scope
     * that drifted between the consent screen and the stored permission would
     * mean somebody agreed to one thing and granted another.
     */
    public function test_the_permission_holds_exactly_what_was_asked_for(): void
    {
        $permission = $this->app->make(Permissions::class)
            ->holder($this->token, Jwk::forP256($this->sessionKey)->thumbprint());

        $this->assertSame(
            ['atproto', 'repo:'.self::CHESS.'?action=create'],
            $permission->scopes(),
        );
    }

    /**
     * The heart of it. A token that worked for whoever held it would be a
     * password, and copying it would be theft of the account.
     */
    public function test_a_token_presented_by_another_key_is_refused(): void
    {
        $this->write(['collection' => self::CHESS, 'record' => $this->attested(['result' => 'win'])], key: P256::generate())
            ->assertUnauthorized();

        $this->assertSame(0, Record::query()->count());
    }

    public function test_a_token_presented_without_a_proof_is_refused(): void
    {
        $url = url('/xrpc/com.atproto.repo.createRecord');

        $this->postJson($url, ['collection' => self::CHESS, 'record' => $this->attested(['result' => 'win'])], [
            'Authorization' => 'DPoP '.$this->token,
        ])->assertUnauthorized();
    }

    /**
     * A proof is bound to the token it accompanies, so one made for a different
     * token cannot be presented alongside this one.
     */
    public function test_a_proof_made_for_a_different_token_is_refused(): void
    {
        $url = url('/xrpc/com.atproto.repo.createRecord');

        $this->postJson($url, ['collection' => self::CHESS, 'record' => $this->attested(['result' => 'win'])], [
            'Authorization' => 'DPoP '.$this->token,
            'DPoP' => Dpop::proof($this->sessionKey, 'POST', $url, accessToken: 'some-other-token'),
        ])->assertUnauthorized();
    }

    public function test_nothing_is_written_without_permission_at_all(): void
    {
        $this->postJson(url('/xrpc/com.atproto.repo.createRecord'), [
            'collection' => self::CHESS,
            'record' => $this->attested(['result' => 'win']),
        ])->assertUnauthorized();

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * Withdrawal has to refuse rather than lapse, and this is the route where
     * that actually matters.
     */
    public function test_writing_stops_the_moment_permission_is_withdrawn(): void
    {
        $permissions = $this->app->make(Permissions::class);

        $this->write(['collection' => self::CHESS, 'record' => $this->attested(['result' => 'win'])])->assertCreated();

        $permissions->withdraw(
            $permissions->holder($this->token, Jwk::forP256($this->sessionKey)->thumbprint())
        );

        $this->write(['collection' => self::CHESS, 'record' => $this->attested(['result' => 'win'])])->assertUnauthorized();

        $this->assertSame(1, Record::query()->count(), 'only the one written while it was allowed');
    }

    // ── Records that are claims rather than statements ──────────────────────

    /**
     * An avatar arrives signed and is not stored signed.
     *
     * Every other record here wraps an attestation, and for those the signature
     * is the point: a venue says who won, and a stranger can check that years
     * later. Nobody is in a position to witness what somebody looks like, so a
     * signature over a face would be a signature over an opinion — and a face
     * written by a venue would be a different shape from the same face the
     * resident wrote at their own settings screen.
     *
     * What is kept instead is the claim, plus which venue put it there.
     */
    public function test_a_face_is_stored_as_a_claim_rather_than_as_a_statement(): void
    {
        $blob = $this->heldBlob();

        $this->write([
            'collection' => Avatars::COLLECTION,
            'record' => $this->attested([
                'name' => 'Weekday',
                'icon' => $blob->reference(),
                'createdAt' => now()->toIso8601ZuluString(),
            ]),
        ], token: $this->grant('atproto '.Scope::forRepo([Avatars::COLLECTION], [Scope::CREATE])))
            ->assertCreated();

        $value = Record::query()->firstOrFail()->value;

        $this->assertSame('Weekday', $value['name']);
        $this->assertSame($blob->cid, $value['icon']['ref']['$link']);
        $this->assertArrayNotHasKey('attestation', $value, 'a face carries no signature over it');
        $this->assertSame(self::VENUE_DID, $value['writtenBy'], 'but it says who wrote it');
    }

    /**
     * Not stored signed is not the same as not required to be signed.
     *
     * The signature is how these bytes are known not to have been altered
     * between the venue and here. Dropping it from the record does not drop it
     * from the conversation.
     */
    public function test_a_claim_still_has_to_arrive_signed(): void
    {
        $this->write([
            'collection' => Avatars::COLLECTION,
            'record' => ['name' => 'Weekday', 'createdAt' => now()->toIso8601ZuluString()],
        ], token: $this->grant('atproto '.Scope::forRepo([Avatars::COLLECTION], [Scope::CREATE])))
            ->assertStatus(400);

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * A venue writing a face changes which face is theirs.
     *
     * The record is the fact and the index beside it is the answer to "which
     * one is current", which a record cannot give. A venue talks to an endpoint
     * that knows nothing about avatars, so if that index were only updated by
     * the settings screen, a face written from a venue would be stored and
     * never shown.
     */
    public function test_a_venue_writing_a_face_changes_which_face_is_theirs(): void
    {
        $blob = $this->heldBlob();

        $this->write([
            'collection' => Avatars::COLLECTION,
            'record' => $this->attested([
                'name' => 'Weekday',
                'icon' => $blob->reference(),
                'createdAt' => now()->toIso8601ZuluString(),
            ]),
        ], token: $this->grant('atproto '.Scope::forRepo([Avatars::COLLECTION], [Scope::CREATE])))
            ->assertCreated();

        $avatar = $this->app->make(Avatars::class)->defaultFor(self::ALICE);

        $this->assertNotNull($avatar);
        $this->assertSame('Weekday', $avatar->name);
        $this->assertSame($blob->cid, $avatar->icon_cid);
        $this->assertSame(Record::query()->firstOrFail()->rkey, $avatar->rkey);
    }

    /**
     * A record may not name bytes nobody is holding.
     *
     * `$link` is a name rather than a reference, and a name is satisfied by
     * whatever happens to be at it — so nothing downstream would ever notice.
     * Refused now, while there is somebody to tell.
     */
    public function test_a_record_naming_bytes_nobody_holds_is_refused(): void
    {
        $this->write([
            'collection' => Avatars::COLLECTION,
            'record' => $this->attested([
                'name' => 'Weekday',
                'icon' => [
                    '$type' => 'blob',
                    'ref' => ['$link' => 'bafkreiaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
                    'mimeType' => 'image/png',
                    'size' => 12,
                ],
                'createdAt' => now()->toIso8601ZuluString(),
            ]),
        ], token: $this->grant('atproto '.Scope::forRepo([Avatars::COLLECTION], [Scope::CREATE])))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');

        $this->assertSame(0, Record::query()->count());
    }

    /**
     * Bytes this server is already holding for the resident in question.
     */
    private function heldBlob(): Blob
    {
        $canvas = imagecreatetruecolor(4, 4);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $this->app->make(BlobStore::class)->put(self::ALICE, $png, Avatars::COLLECTION);
    }
}
