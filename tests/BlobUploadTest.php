<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use StreetMesh\Protocol\BlobScope;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Protocol\Glb;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Protocol\Blobs\Blob;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use StreetMesh\Server\Protocol\Permissions\Spent;

/**
 * A venue putting bytes into somebody else's store.
 *
 * There was deliberately no endpoint for this for as long as `blob:` was a
 * scope nothing parsed: a permission the endpoint cannot enforce and the
 * consent screen cannot describe is a permission granted by nobody. So the
 * tests here are mostly about refusals — the endpoint exists to say no in the
 * right places, and saying yes is the easy half.
 */
class BlobUploadTest extends TestCase
{
    private const VENUE = 'https://games.test/client-metadata.json';

    private const CHESS = 'com.streetmesh.games.chess';

    private const ALICE = 'did:plc:alice';

    private P256 $venueKey;

    private P256 $sessionKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venueKey = P256::generate();
        $this->sessionKey = P256::generate();

        $this->app->instance(Network::class, (new FakeNetwork)
            ->serve(self::VENUE, [
                'client_id' => self::VENUE,
                'redirect_uris' => ['https://games.test/connect/callback'],
                'jwks_uri' => 'https://games.test/jwks.json',
            ])
            ->serve('https://games.test/jwks.json', ClientMetadata::keySet([
                'atproto' => Jwk::forP256($this->venueKey),
            ])));
    }

    // ── Spending a permission ───────────────────────────────────────────────

    public function test_bytes_a_permission_covers_are_kept_and_named_by_their_content(): void
    {
        $picture = $this->picture();

        $response = $this->upload($picture, token: $this->grant());

        $response->assertCreated()
            ->assertJsonPath('blob.$type', 'blob')
            ->assertJsonPath('blob.mimeType', 'image/png')
            ->assertJsonPath('blob.size', strlen($picture));

        $blob = Blob::query()->firstOrFail();

        $this->assertSame(self::ALICE, $blob->did, 'the bytes belong to the resident, not the venue');
        $this->assertSame(self::CHESS, $blob->collection);
        $this->assertSame($blob->cid, $response->json('blob.ref.$link'));
    }

    /**
     * A model is stored as a model, whatever this machine's libmagic knows.
     */
    public function test_a_model_is_recognised_and_kept(): void
    {
        $this->upload($this->model(), token: $this->grant('blob:'.Glb::MIME))
            ->assertCreated()
            ->assertJsonPath('blob.mimeType', Glb::MIME);
    }

    /**
     * Storing the same bytes twice is the same blob referred to again.
     */
    public function test_the_same_bytes_twice_are_one_blob(): void
    {
        $token = $this->grant();
        $picture = $this->picture();

        $first = $this->upload($picture, token: $token)->json('blob.ref.$link');
        $second = $this->upload($picture, token: $token)->json('blob.ref.$link');

        $this->assertSame($first, $second);
        $this->assertSame(1, Blob::query()->count());
    }

    // ── What is refused ─────────────────────────────────────────────────────

    public function test_a_token_presented_without_a_proof_is_refused(): void
    {
        $url = url('/xrpc/com.atproto.repo.uploadBlob?collection='.self::CHESS);

        $this->call('POST', $url, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->grant(),
        ], content: $this->picture())->assertUnauthorized();
    }

    /**
     * Being allowed to keep a picture is not being allowed to write a game.
     */
    public function test_a_permission_that_cannot_write_the_record_cannot_keep_bytes_for_it(): void
    {
        $token = $this->grant(
            'blob:image/png',
            record: (string) Scope::forRepo(['com.streetmesh.messages.direct'], [Scope::CREATE]),
        );

        $this->upload($this->picture(), token: $token)
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    }

    /**
     * And being allowed to write a game is not being allowed to keep a model.
     *
     * The refusal names the scope that would have worked, so a venue author
     * reading a log learns what to ask for rather than that something is wrong.
     */
    public function test_a_permission_that_does_not_cover_the_type_is_refused_by_type(): void
    {
        $this->upload($this->model(), token: $this->grant('blob:image/png'))
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertJsonPath('scope', 'blob:'.Glb::MIME);

        $this->assertSame(0, Blob::query()->count());
    }

    /**
     * A permission with no blob scope at all keeps nothing, which is what every
     * permission granted before this endpoint existed looks like.
     */
    public function test_a_permission_from_before_any_of_this_keeps_nothing(): void
    {
        $this->upload($this->picture(), token: $this->grant(blob: null))->assertForbidden();
    }

    /**
     * The caller does not get to say what the bytes are.
     *
     * Announcing a model as a PNG gets the answer the bytes deserve rather than
     * the one that was asked for -- which is the whole reason the type is
     * looked at instead of accepted.
     */
    public function test_what_the_caller_calls_the_bytes_is_not_read(): void
    {
        $this->upload($this->model(), token: $this->grant('blob:image/png'), announcedAs: 'image/png')
            ->assertForbidden()
            ->assertJsonPath('scope', 'blob:'.Glb::MIME);
    }

    /**
     * Bytes are kept *for* something, and a blob's visibility is looked up from
     * that. With no collection there is no answer to who may read them.
     */
    public function test_bytes_with_no_collection_are_refused(): void
    {
        $url = url('/xrpc/com.atproto.repo.uploadBlob');
        $token = $this->grant();

        $this->call('POST', $url, server: [
            'HTTP_AUTHORIZATION' => 'DPoP '.$token,
            'HTTP_DPOP' => Dpop::proof($this->sessionKey, 'POST', $url, accessToken: $token),
        ], content: $this->picture())
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    public function test_nothing_at_all_is_refused(): void
    {
        $this->upload('', token: $this->grant())->assertStatus(400);
    }

    public function test_a_type_this_server_does_not_keep_is_refused(): void
    {
        $this->upload('just some words', token: $this->grant('blob:'.BlobScope::ANY))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    public function test_more_bytes_than_this_server_keeps_are_refused(): void
    {
        config(['streetmesh.blobs.limits' => ['image/png' => 8]]);

        $this->upload($this->picture(), token: $this->grant())
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    // ── Arranging it ────────────────────────────────────────────────────────

    /**
     * @return TestResponse<JsonResponse>
     */
    private function upload(string $bytes, string $token, ?string $announcedAs = null): TestResponse
    {
        $url = url('/xrpc/com.atproto.repo.uploadBlob?collection='.self::CHESS);

        return $this->call('POST', $url, server: array_filter([
            'HTTP_AUTHORIZATION' => 'DPoP '.$token,
            'HTTP_DPOP' => Dpop::proof($this->sessionKey, 'POST', $url, accessToken: $token),
            'CONTENT_TYPE' => $announcedAs,
        ]), content: $bytes);
    }

    /**
     * A permission, arranged the long way, because a token conjured directly
     * would not prove the thing being tested.
     */
    private function grant(?string $blob = 'blob:image/png', ?string $record = null): string
    {
        $record ??= (string) Scope::forRepo([self::CHESS], [Scope::CREATE]);

        $permissions = new Permissions($this->app->make(Network::class), $this->app->make(Spent::class));
        $issuer = 'https://games.test';
        $pkce = Pkce::generate();
        $thumbprint = Jwk::forP256($this->sessionKey)->thumbprint();

        $pushed = $permissions->push([
            'client_id' => self::VENUE,
            'redirect_uri' => 'https://games.test/connect/callback',
            'scope' => implode(' ', array_filter(['atproto', $record, $blob])),
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

    private function picture(): string
    {
        $canvas = imagecreatetruecolor(8, 8);

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    /**
     * The smallest thing this server will agree is a model.
     */
    private function model(): string
    {
        $json = str_pad('{"asset":{"version":"2.0"},"extensionsUsed":["VRMC_vrm"]}', 60, ' ');
        $chunk = pack('VV', strlen($json), 0x4E4F534A).$json;

        return 'glTF'.pack('VV', 2, 12 + strlen($chunk)).$chunk;
    }
}
