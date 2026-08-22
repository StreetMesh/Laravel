<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;
use StreetMesh\Server\Protocol\Permissions\Permission;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use StreetMesh\Server\Protocol\Permissions\Spent;

/**
 * A venue and a domicile that have never met, all the way to a token.
 *
 * The whole point of the exchange is that nothing was arranged beforehand: no
 * registration, no shared secret, no row on either side naming the other. So
 * these set up a venue that exists only as two documents on the network, and
 * check that a domicile can go from never having heard of it to granting it
 * something — and, more importantly, that it refuses at every point where it
 * should.
 */
class PermissionTest extends TestCase
{
    private const VENUE = 'https://games.test/client-metadata.json';

    private const REDIRECT = 'https://games.test/connect/callback';

    private const ISSUER = 'https://home.test';

    private P256 $venueKey;

    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venueKey = P256::generate();
        $this->network = new FakeNetwork;

        // The venue, as far as anybody else can see: two documents and nothing
        // else. No account here, no prior arrangement, nothing stored.
        $this->network
            ->serve(self::VENUE, [
                'client_id' => self::VENUE,
                'redirect_uris' => [self::REDIRECT],
                'jwks_uri' => 'https://games.test/jwks.json',
                'scope' => 'atproto',
            ])
            ->serve('https://games.test/jwks.json', ClientMetadata::keySet([
                'atproto' => Jwk::forP256($this->venueKey),
            ]));

        $this->app->instance(Network::class, $this->network);
    }

    private function permissions(): Permissions
    {
        return new Permissions($this->network, $this->app->make(Spent::class));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function request(Pkce $pkce, array $overrides = []): array
    {
        return [
            'client_id' => self::VENUE,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'atproto',
            'state' => 'a-state',
            'code_challenge' => $pkce->challenge(),
            'code_challenge_method' => 'S256',
            'client_assertion_type' => ClientAssertion::TYPE,
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
            ...$overrides,
        ];
    }

    /**
     * The whole exchange, from a venue this server has never heard of to a
     * token bound to the key that asked for it.
     */
    public function test_a_stranger_can_be_granted_permission_without_anything_agreed_beforehand(): void
    {
        $permissions = $this->permissions();
        $pkce = Pkce::generate();

        $pushed = $permissions->push($this->request($pkce), self::ISSUER, 'the-venue-key');

        $this->assertStringStartsWith('urn:ietf:params:oauth:request_uri:', (string) $pushed->request_uri);
        $this->assertNull($pushed->did, 'nobody has agreed to anything yet');

        $code = $permissions->approve($permissions->pending((string) $pushed->request_uri), 'did:plc:alice');

        $granted = $permissions->redeem([
            'client_id' => self::VENUE,
            'code' => $code,
            'code_verifier' => $pkce->verifier,
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'the-venue-key');

        $this->assertSame('did:plc:alice', $granted['permission']->did);
        $this->assertNotSame($granted['access'], $granted['refresh']);

        // And the token is only usable by the key it was issued to.
        $this->assertSame(
            $granted['permission']->id,
            $permissions->holder($granted['access'], 'the-venue-key')->id,
        );
    }

    /**
     * The heart of DPoP. A token that worked for whoever was holding it would
     * be a bearer token with extra steps.
     */
    public function test_a_token_is_worthless_to_a_different_key(): void
    {
        $granted = $this->grant();

        $this->expectException(RuntimeException::class);

        $this->permissions()->holder($granted['access'], 'somebody-elses-key');
    }

    /**
     * The point of the challenge: whatever intercepted the code on its way back
     * through the browser has the code and not the secret behind it.
     */
    public function test_a_code_cannot_be_spent_without_the_secret_it_was_bound_to(): void
    {
        $permissions = $this->permissions();
        $pushed = $permissions->push($this->request(Pkce::generate()), self::ISSUER, 'the-venue-key');
        $code = $permissions->approve($permissions->pending((string) $pushed->request_uri), 'did:plc:alice');

        $this->expectException(RuntimeException::class);

        $permissions->redeem([
            'client_id' => self::VENUE,
            'code' => $code,
            'code_verifier' => Pkce::generate()->verifier,
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'the-venue-key');
    }

    /**
     * A stolen code should not be spendable by somebody who simply brings their
     * own key to the exchange.
     */
    public function test_a_code_cannot_be_spent_by_a_key_that_did_not_ask_for_it(): void
    {
        $permissions = $this->permissions();
        $pkce = Pkce::generate();
        $pushed = $permissions->push($this->request($pkce), self::ISSUER, 'the-venue-key');
        $code = $permissions->approve($permissions->pending((string) $pushed->request_uri), 'did:plc:alice');

        $this->expectException(RuntimeException::class);

        $permissions->redeem([
            'client_id' => self::VENUE,
            'code' => $code,
            'code_verifier' => $pkce->verifier,
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'a-key-that-turned-up-later');
    }

    /**
     * Without this anybody could ask for a code and have it delivered wherever
     * they liked.
     */
    public function test_a_redirect_the_venue_does_not_publish_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->permissions()->push(
            $this->request(Pkce::generate(), ['redirect_uri' => 'https://somewhere.else/callback']),
            self::ISSUER,
            'the-venue-key',
        );
    }

    /**
     * `plain` is permitted by the base specification and forbidden by this
     * profile, and accepting it would make the challenge worthless while
     * looking like a courtesy.
     */
    public function test_a_weaker_challenge_method_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->permissions()->push(
            $this->request(Pkce::generate(), ['code_challenge_method' => 'plain']),
            self::ISSUER,
            'the-venue-key',
        );
    }

    /**
     * A venue serving a document that names somebody else could borrow whatever
     * that somebody had been trusted with.
     */
    public function test_a_client_document_naming_a_different_client_is_refused(): void
    {
        $this->network->serve(self::VENUE, [
            'client_id' => 'https://impostor.test/client-metadata.json',
            'redirect_uris' => [self::REDIRECT],
            'jwks_uri' => 'https://games.test/jwks.json',
        ]);

        $this->expectException(RuntimeException::class);

        $this->permissions()->push($this->request(Pkce::generate()), self::ISSUER, 'the-venue-key');
    }

    public function test_an_assertion_signed_by_a_key_the_venue_does_not_publish_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->permissions()->push(
            $this->request(Pkce::generate(), [
                'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, P256::generate()),
            ]),
            self::ISSUER,
            'the-venue-key',
        );
    }

    /**
     * An assertion overheard once would otherwise be presentable again for as
     * long as it had left to live.
     */
    public function test_an_assertion_cannot_be_used_twice(): void
    {
        $assertion = ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey);

        $this->permissions()->push($this->request(Pkce::generate(), ['client_assertion' => $assertion]), self::ISSUER, 'k');

        $this->expectException(RuntimeException::class);

        $this->permissions()->push($this->request(Pkce::generate(), ['client_assertion' => $assertion]), self::ISSUER, 'k');
    }

    /**
     * Withdrawal has to genuinely refuse rather than quietly lapse, and must
     * not be routed around by refreshing.
     */
    public function test_withdrawing_refuses_afterwards_and_cannot_be_refreshed_around(): void
    {
        $granted = $this->grant();
        $permissions = $this->permissions();

        $permissions->withdraw($granted['permission']);

        foreach ([
            fn () => $permissions->holder($granted['access'], 'the-venue-key'),
            fn () => $permissions->refresh([
                'client_id' => self::VENUE,
                'refresh_token' => $granted['refresh'],
                'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
            ], self::ISSUER, 'the-venue-key'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a withdrawn permission was still honoured');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Refresh tokens are replaced as they are used, so a copy of a spent one is
     * worth nothing.
     */
    public function test_a_refresh_token_is_replaced_when_it_is_used(): void
    {
        $granted = $this->grant();
        $permissions = $this->permissions();

        $refreshed = $permissions->refresh([
            'client_id' => self::VENUE,
            'refresh_token' => $granted['refresh'],
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'the-venue-key');

        $this->assertNotSame($granted['refresh'], $refreshed['refresh']);
        $this->assertNotSame($granted['access'], $refreshed['access']);

        $this->expectException(RuntimeException::class);

        $permissions->refresh([
            'client_id' => self::VENUE,
            'refresh_token' => $granted['refresh'],
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'the-venue-key');
    }

    /**
     * Nothing readable is kept. A leaked table should not be a leaked account.
     */
    public function test_no_secret_is_stored_in_a_form_anybody_could_use(): void
    {
        $granted = $this->grant();

        $row = Permission::query()->find($granted['permission']->id);

        foreach ([$granted['access'], $granted['refresh']] as $secret) {
            $this->assertNotContains($secret, (array) $row?->getAttributes());
        }

        $this->assertSame(hash('sha256', $granted['access']), $row?->token_hash);
    }

    /**
     * @return array{permission: Permission, access: string, refresh: string}
     */
    private function grant(): array
    {
        $permissions = $this->permissions();
        $pkce = Pkce::generate();

        $pushed = $permissions->push($this->request($pkce), self::ISSUER, 'the-venue-key');
        $code = $permissions->approve($permissions->pending((string) $pushed->request_uri), 'did:plc:alice');

        return $permissions->redeem([
            'client_id' => self::VENUE,
            'code' => $code,
            'code_verifier' => $pkce->verifier,
            'client_assertion' => ClientAssertion::for(self::VENUE, self::ISSUER, $this->venueKey),
        ], self::ISSUER, 'the-venue-key');
    }
}
