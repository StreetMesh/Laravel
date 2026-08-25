<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Protocol\Permissions\Delegations;
use StreetMesh\Server\Venue\Http\ConnectController;

/**
 * The venue's half: asking a server nobody here has heard of, and spending what
 * it gives back.
 *
 * `Permissions` answers strangers; this goes out to one. The domicile is faked
 * here because what is being tested is our side of the conversation — the real
 * pairing of the two halves is `bin/check-permission.php`, over HTTP.
 */
class DelegationTest extends TestCase
{
    private const THEIR_SERVER = 'https://home.test';

    private const CHESS = 'com.streetmesh.games.chess';

    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A domicile, as far as discovery can see: a handle, an identity, a
         * repository server, and the two documents that say what it will do.
         */
        $this->network = (new FakeNetwork)
            ->serve('https://alice.home.test/.well-known/atproto-did', 'did:web:alice.home.test')
            ->serve('https://alice.home.test/.well-known/did.json', [
                'id' => 'did:web:alice.home.test',
                'alsoKnownAs' => ['at://alice.home.test'],
                'service' => [[
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => self::THEIR_SERVER,
                ]],
            ])
            ->serve(self::THEIR_SERVER.'/.well-known/oauth-protected-resource', [
                'authorization_servers' => [self::THEIR_SERVER],
            ])
            ->serve(self::THEIR_SERVER.'/.well-known/oauth-authorization-server', [
                'issuer' => self::THEIR_SERVER,
                'pushed_authorization_request_endpoint' => self::THEIR_SERVER.'/oauth/par',
                'authorization_endpoint' => self::THEIR_SERVER.'/oauth/authorize',
                'token_endpoint' => self::THEIR_SERVER.'/oauth/token',
                'require_pushed_authorization_requests' => true,
                'client_id_metadata_document_supported' => true,
                'dpop_signing_alg_values_supported' => ['ES256'],
            ]);

        $this->app->instance(Network::class, $this->network);

        /*
         * And this server's own identity, because what it signs has to be
         * checkable — including by this test, standing in for a stranger.
         */
        $ours = $this->app->make(Identities::class)->forServer();

        $this->network->serve('https://games.test/.well-known/did.json', [
            'id' => $ours->did,
            'verificationMethod' => [[
                'id' => $ours->keyId(),
                'type' => 'Multikey',
                'controller' => $ours->did,
                'publicKeyMultibase' => $ours->key()->multikey(),
            ]],
        ]);
    }

    private function delegations(): Delegations
    {
        return $this->app->make(Delegations::class);
    }

    public function test_a_name_somebody_typed_becomes_somewhere_to_send_them(): void
    {
        Http::fake([
            self::THEIR_SERVER.'/oauth/par' => Http::response([
                'request_uri' => 'urn:ietf:params:oauth:request_uri:abc',
            ], 201),
        ]);

        $begun = $this->delegations()->begin('alice.home.test', [(string) Scope::forRepo([self::CHESS], [Scope::CREATE])], 'https://games.test/connect/callback');

        $this->assertStringStartsWith(self::THEIR_SERVER.'/oauth/authorize?', $begun['url']);

        parse_str((string) parse_url($begun['url'], PHP_URL_QUERY), $query);

        // Nothing about the request travels in their browser but the handle.
        $this->assertSame(['client_id', 'request_uri'], array_keys($query));

        $this->assertSame(self::THEIR_SERVER, $begun['delegation']->issuer);
        $this->assertNull($begun['delegation']->access_token, 'nobody has agreed to anything yet');
    }

    /**
     * The nonce dance, which is the thing most likely to be got wrong: a client
     * that treats `use_dpop_nonce` as a failure works until the first rotation
     * and then breaks at a boundary it cannot see.
     */
    public function test_being_told_to_use_a_new_nonce_is_an_ordinary_event(): void
    {
        $asked = 0;

        Http::fake([
            self::THEIR_SERVER.'/oauth/par' => function () use (&$asked) {
                $asked++;

                return $asked === 1
                    ? Http::response(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'a-fresh-nonce'])
                    : Http::response(['request_uri' => 'urn:ietf:params:oauth:request_uri:abc'], 201);
            },
        ]);

        $begun = $this->delegations()->begin('alice.home.test', [], 'https://games.test/connect/callback');

        $this->assertSame(2, $asked, 'it should have retried with the nonce it was handed');
        $this->assertStringContainsString('request_uri', $begun['url']);
    }

    public function test_a_code_becomes_a_token_this_server_can_spend(): void
    {
        $delegation = $this->begun();

        Http::fake([
            self::THEIR_SERVER.'/oauth/token' => Http::response([
                'access_token' => 'a-live-token',
                'refresh_token' => 'a-refresh-token',
                'token_type' => 'DPoP',
                'expires_in' => 900,
                'scope' => 'atproto '.Scope::forRepo([self::CHESS], [Scope::CREATE]),
                'sub' => 'did:web:alice.home.test',
            ]),
        ]);

        $finished = $this->delegations()->complete(
            (string) $delegation->state,
            'a-code',
            'https://games.test/connect/callback',
        );

        $this->assertSame('a-live-token', $finished->access_token);
        $this->assertSame('did:web:alice.home.test', $finished->did);
        $this->assertTrue($finished->permits(self::CHESS));

        /*
         * Both cleared. The verifier has done its work, and a state that still
         * matched would be a callback somebody could replay.
         */
        $this->assertNull($finished->state);
        $this->assertNull($finished->code_verifier);
    }

    /**
     * Somebody signs in to their server as themselves, having asked to arrive
     * here as somebody else.
     *
     * A domicile authenticates whoever is signed in to it and `login_hint` is
     * advisory, so this is reachable without anybody lying: an autofilled login
     * form is enough. Left unchecked, this venue would carry the name it asked
     * for and the identity it was handed, in the same row — showing one person
     * to everybody here while signing records on behalf of another.
     */
    public function test_a_server_answering_for_somebody_else_is_refused(): void
    {
        $delegation = $this->begun();

        Http::fake([
            self::THEIR_SERVER.'/oauth/token' => Http::response([
                'access_token' => 'a-live-token',
                'refresh_token' => 'a-refresh-token',
                'token_type' => 'DPoP',
                'expires_in' => 900,
                'scope' => 'atproto',
                'sub' => 'did:web:bob.home.test',
            ]),
        ]);

        try {
            $this->delegations()->complete(
                (string) $delegation->state,
                'a-code',
                'https://games.test/connect/callback',
            );

            $this->fail('a token issued for somebody else should not have been kept');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('did:web:bob.home.test', $refused->getMessage());
            $this->assertStringContainsString('alice.home.test', $refused->getMessage());
        }

        /*
         * And nothing was written. A refusal that still stored the token would
         * leave the venue holding permission it had just called invalid.
         */
        $delegation->refresh();

        $this->assertSame('did:web:alice.home.test', $delegation->did);
        $this->assertNull($delegation->access_token);
        $this->assertNotNull($delegation->state, 'a refused callback is not a spent one');
    }

    /**
     * A callback carrying a state nobody issued is somebody else's business,
     * and this is the only thing standing between that and a token request.
     */
    public function test_an_answer_to_a_question_nobody_asked_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->delegations()->complete('a-state-we-never-issued', 'a-code', 'https://games.test/connect/callback');
    }

    public function test_a_record_is_signed_before_it_is_sent(): void
    {
        $delegation = $this->granted();
        $sent = null;

        Http::fake([
            self::THEIR_SERVER.'/xrpc/*' => function ($request) use (&$sent) {
                $sent = $request->data();

                return Http::response(['uri' => 'at://did:web:alice.home.test/'.self::CHESS.'/3abc', 'cid' => 'bafy'], 201);
            },
        ]);

        $written = $this->delegations()->write($delegation, self::CHESS, ['result' => 'win']);

        $this->assertSame('at://did:web:alice.home.test/'.self::CHESS.'/3abc', $written['uri']);

        // What travelled is a signature, not a payload with our word for it.
        $this->assertArrayHasKey('attestation', $sent['record']);
        $this->assertSame(['attestation'], array_keys($sent['record']));

        $checked = $this->app->make(Attestations::class)
            ->verify($sent['record']['attestation']);

        $this->assertSame('win', $checked->claim('result'));
    }

    /**
     * The scope a visitor agreed to is checked before anything is sent, so a
     * venue asking for something they did not agree to finds out here rather
     * than from a stranger's server.
     */
    public function test_a_record_outside_what_they_agreed_to_is_not_even_attempted(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->delegations()->write($this->granted(), 'com.streetmesh.messages.direct', ['body' => 'hello']);
    }

    /**
     * Refreshed before use rather than after failure, because the failure a
     * visitor would otherwise see is one this server could have avoided by
     * looking at a clock.
     */
    public function test_a_token_nearly_out_is_renewed_before_it_is_used(): void
    {
        /*
         * Built directly rather than granted, because Laravel's HTTP fakes
         * match in the order they were registered — a stub left over from
         * obtaining the first token would answer the refresh as well.
         */
        $delegation = Delegation::create([
            'did' => 'did:web:alice.home.test',
            'handle' => 'alice.home.test',
            'issuer' => self::THEIR_SERVER,
            'dpop_key' => Delegation::store(P256::generate()),
            'access_token' => 'a-live-token',
            'refresh_token' => 'a-refresh-token',
            'scope' => 'atproto '.Scope::forRepo([self::CHESS], [Scope::CREATE]),
            'expires_at' => now()->addSeconds(5),
        ]);

        $this->assertTrue($delegation->isStale());
        $this->assertTrue($delegation->isStale(), 'asking twice must not move the expiry');

        Http::fake([
            self::THEIR_SERVER.'/oauth/token' => Http::response([
                'access_token' => 'a-renewed-token',
                'refresh_token' => 'another-refresh-token',
                'expires_in' => 900,
                'scope' => $delegation->scope,
            ]),
        ]);

        $live = $this->delegations()->live($delegation);

        $this->assertSame('a-renewed-token', $live->access_token);
        $this->assertFalse($live->isStale());
    }

    /**
     * A token is spendable and the key is what makes it spendable, so a leaked
     * table should not be a leaked visitor.
     */
    public function test_nothing_spendable_is_stored_in_the_clear(): void
    {
        $delegation = $this->granted();

        $row = (array) Delegation::query()->whereKey($delegation->id)->toBase()->first();

        foreach (['access_token', 'refresh_token', 'dpop_key'] as $secret) {
            $this->assertNotSame($delegation->{$secret}, $row[$secret], "{$secret} is readable in the database");
        }

        // And the key still works after the round trip through encryption.
        $this->assertInstanceOf(P256::class, $delegation->key());
    }

    private function begun(): Delegation
    {
        Http::fake([
            self::THEIR_SERVER.'/oauth/par' => Http::response([
                'request_uri' => 'urn:ietf:params:oauth:request_uri:abc',
            ], 201),
        ]);

        return $this->delegations()->begin(
            'alice.home.test',
            [(string) Scope::forRepo([self::CHESS], [Scope::CREATE])],
            'https://games.test/connect/callback',
        )['delegation'];
    }

    private function granted(): Delegation
    {
        $delegation = $this->begun();

        Http::fake([
            self::THEIR_SERVER.'/oauth/token' => Http::response([
                'access_token' => 'a-live-token',
                'refresh_token' => 'a-refresh-token',
                'expires_in' => 900,
                'scope' => 'atproto '.Scope::forRepo([self::CHESS], [Scope::CREATE]),
                'sub' => 'did:web:alice.home.test',
            ]),
        ]);

        return $this->delegations()->complete((string) $delegation->state, 'a-code', 'https://games.test/connect/callback');
    }
    // ── Four ways the door does not open ────────────────────────────────────

    /**
     * @return TestResponse<Response>
     */
    private function knock(string $handle)
    {
        return $this->from(route('venue.connect'))
            ->post(route('venue.connect.start'), ['handle' => $handle]);
    }

    private function refusalFor(string $handle): string
    {
        $this->knock($handle)->assertRedirect();

        $errors = session('errors');

        return $errors instanceof ViewErrorBag
            ? (string) $errors->getBag('default')->first(ConnectController::REFUSAL)
            : '';
    }

    /**
     * The four cases used to leave here as one sentence about a typo.
     *
     * Three of them are not typos, and one of them is not even a problem with
     * the address — so somebody was sent to check their spelling while the
     * spelling was fine. Each is now told what actually happened.
     */
    public function test_a_name_that_answers_nowhere_is_told_so(): void
    {
        $this->assertStringContainsString(
            'Nothing at nobody.home.test answers',
            $this->refusalFor('nobody.home.test'),
        );
    }

    public function test_an_identity_that_disowns_the_name_is_not_dressed_as_a_typo(): void
    {
        // Resolves, and the document it points at answers to somebody else.
        $this->network
            ->serve('https://impostor.home.test/.well-known/atproto-did', 'did:web:alice.home.test')
            ->serve('https://impostor.home.test/.well-known/did.json', [
                'id' => 'did:web:alice.home.test',
                'alsoKnownAs' => ['at://alice.home.test'],
            ]);

        $said = $this->refusalFor('impostor.home.test');

        $this->assertStringContainsString('does not answer to that name', $said);

        /*
         * The point of the test. This is a server claiming an identity that
         * disowns the claim, and telling somebody to check their spelling would
         * quietly turn the bidirectional check into decoration.
         */
        $this->assertStringNotContainsString('Nothing at', $said);
    }

    /**
     * A real address this venue's directory has never heard of.
     *
     * Constant in development, where a venue on a local directory is handed a
     * production handle. The address, the spelling and the person are all fine.
     */
    public function test_an_identity_that_cannot_be_looked_up_says_the_address_is_real(): void
    {
        $this->network->serve('https://elsewhere.home.test/.well-known/atproto-did', 'did:plc:unknowntoallofus');

        $said = $this->refusalFor('elsewhere.home.test');

        $this->assertStringContainsString('is a real address', $said);
        $this->assertStringContainsString('cannot look up the identity', $said);
    }

    /**
     * Discovery worked and the handshake did not.
     *
     * Nothing the visitor can do, and nothing wrong with what they typed, so
     * the one thing not to say is anything about their address.
     */
    public function test_a_server_that_will_not_finish_says_it_is_not_the_address(): void
    {
        $this->network->serve(self::THEIR_SERVER.'/.well-known/oauth-authorization-server', [
            'issuer' => self::THEIR_SERVER,
            'pushed_authorization_request_endpoint' => self::THEIR_SERVER.'/oauth/par',
            'authorization_endpoint' => self::THEIR_SERVER.'/oauth/authorize',
            'token_endpoint' => self::THEIR_SERVER.'/oauth/token',
            'require_pushed_authorization_requests' => true,
            'client_id_metadata_document_supported' => true,

            // Nothing this server can sign with.
            'dpop_signing_alg_values_supported' => ['RS256'],
        ]);

        $said = $this->refusalFor('alice.home.test');

        $this->assertStringContainsString('Nothing is wrong with alice.home.test', $said);
    }

    // ── A free name is an offer, not a refusal ──────────────────────────────

    /**
     * A real domicile, with nobody at the name that was typed.
     */
    private function domicileWithNobodyHome(string $host = 'home.test'): void
    {
        $this->network->serve('https://'.$host.'/.well-known/did.json', [
            'id' => 'did:web:'.$host,
            'alsoKnownAs' => ['at://'.$host],
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://'.$host,
            ]],
        ]);
    }

    public function test_a_free_name_at_a_real_domicile_is_offered_rather_than_refused(): void
    {
        $this->domicileWithNobodyHome();

        $said = $this->refusalFor('nobody.home.test');

        $this->assertStringContainsString('Nobody has nobody.home.test yet', $said);

        // And the screen is handed somewhere to send them.
        $this->assertSame(
            ['handle' => 'nobody.home.test', 'domicile' => 'home.test', 'url' => 'https://home.test/register'],
            session('connect.vacancy'),
        );
    }

    /**
     * The guard that keeps this from being a way to send people anywhere.
     *
     * The offer is built from a string somebody typed into a public form, so it
     * is only ever made about a host that has answered for itself first and said
     * it keeps repositories. A typo, a dead host, or somewhere that is simply not
     * a StreetMesh server gets the ordinary sentence and no link at all.
     */
    public function test_a_name_at_somewhere_that_is_not_a_domicile_is_only_refused(): void
    {
        foreach (['nobody.nowhere.test', 'nobody.evil.example'] as $handle) {
            session()->forget('connect.vacancy');

            $said = $this->refusalFor($handle);

            $this->assertStringContainsString('answers as a StreetMesh address', $said, $handle);
            $this->assertNull(session('connect.vacancy'), $handle);
        }
    }

    /**
     * A venue is not somewhere people live, and does not claim to be.
     *
     * So a name at another venue produces no offer — nobody there is handing out
     * addresses, and sending somebody to find out would waste the one moment
     * they were willing to go and get one.
     */
    public function test_a_name_at_a_venue_is_not_offered(): void
    {
        $this->network->serve('https://games.example/.well-known/did.json', [
            'id' => 'did:web:games.example',
            'alsoKnownAs' => ['at://games.example'],
            'service' => [[
                'id' => '#streetmesh_venue',
                'type' => 'StreetMeshVenue',
                'serviceEndpoint' => 'https://games.example',
            ]],
        ]);

        $this->refusalFor('nobody.games.example');

        $this->assertNull(session('connect.vacancy'));
    }
}
