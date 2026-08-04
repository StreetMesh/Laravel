<?php

namespace StreetMesh\Protocol\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\AuthorizationRequest;
use RuntimeException;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\Jws;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;

class AuthorizationRequestTest extends TestCase
{
    private const CLIENT = 'https://games.test/client-metadata.json';

    private const REDIRECT = 'https://games.test/connect/callback';

    /**
     * @return array<string, string>
     */
    private function pushed(P256 $key, Pkce $pkce, ?string $hint = null): array
    {
        return AuthorizationRequest::pushed(
            clientId: self::CLIENT,
            redirectUri: self::REDIRECT,
            state: 'a-state',
            pkce: $pkce,
            assertion: ClientAssertion::for(self::CLIENT, 'https://auth.example', $key),
            loginHint: $hint,
        );
    }

    /**
     * The secret stays home until the code is being spent. Anything that
     * intercepts the code on its way back through the browser has the code and
     * not this, which is the entire mechanism.
     */
    public function test_the_pushed_request_carries_the_hash_and_never_the_secret(): void
    {
        $pkce = Pkce::generate();

        $fields = $this->pushed(P256::generate(), $pkce);

        $this->assertSame($pkce->challenge(), $fields['code_challenge']);
        $this->assertSame('S256', $fields['code_challenge_method']);
        $this->assertNotContains($pkce->verifier, $fields);
    }

    public function test_the_verifier_appears_only_when_the_code_is_redeemed(): void
    {
        $pkce = Pkce::generate();

        $fields = AuthorizationRequest::redeem(
            clientId: self::CLIENT,
            redirectUri: self::REDIRECT,
            code: 'a-code',
            pkce: $pkce,
            assertion: 'an-assertion',
        );

        $this->assertSame($pkce->verifier, $fields['code_verifier']);
        $this->assertSame('authorization_code', $fields['grant_type']);
    }

    /**
     * Everything the venue wants is already at the other end under a handle, so
     * the person's browser carries none of it. That is what PAR buys, and a
     * redirect carrying scopes or a redirect_uri would be giving it back.
     */
    public function test_the_redirect_carries_nothing_but_a_handle(): void
    {
        $url = AuthorizationRequest::redirectTo(
            'https://auth.example/oauth/authorize',
            'urn:ietf:params:oauth:request_uri:abc',
            self::CLIENT,
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(['client_id', 'request_uri'], array_keys($query));
        $this->assertSame('urn:ietf:params:oauth:request_uri:abc', $query['request_uri']);
    }

    public function test_the_address_somebody_typed_is_passed_on_as_a_hint(): void
    {
        $withHint = $this->pushed(P256::generate(), Pkce::generate(), 'alice.example');
        $without = $this->pushed(P256::generate(), Pkce::generate());

        $this->assertSame('alice.example', $withHint['login_hint']);
        $this->assertArrayNotHasKey('login_hint', $without);
    }

    /**
     * A signature rather than a secret, and one addressed to a single server —
     * an assertion with no audience could be collected by one and presented to
     * another as though the venue had addressed it.
     */
    public function test_the_assertion_names_the_venue_and_the_server_it_addresses(): void
    {
        $key = P256::generate();

        $claims = Jws::verify(
            ClientAssertion::for(self::CLIENT, 'https://auth.example', $key),
            $key->multikey(),
        );

        $this->assertSame(self::CLIENT, $claims['iss']);
        $this->assertSame(self::CLIENT, $claims['sub']);
        $this->assertSame('https://auth.example', $claims['aud']);
        $this->assertSame($claims['iat'] + ClientAssertion::LIFETIME_SECONDS, $claims['exp']);
    }

    public function test_every_assertion_is_its_own(): void
    {
        $key = P256::generate();

        $identifiers = array_map(
            fn (): string => Jws::verify(ClientAssertion::for(self::CLIENT, 'https://auth.example', $key), $key->multikey())['jti'],
            range(1, 10),
        );

        $this->assertCount(10, array_unique($identifiers));
    }

    /**
     * The key has to be findable in what the venue publishes, and `kid` is how.
     */
    public function test_the_assertion_says_which_published_key_signed_it(): void
    {
        $key = P256::generate();
        $assertion = ClientAssertion::for(self::CLIENT, 'https://auth.example', $key);

        $header = json_decode(
            (string) base64_decode(strtr(explode('.', $assertion)[0], '-_', '+/'), true),
            true,
        );

        $this->assertSame('atproto', $header['kid']);
        $this->assertSame('ES256', $header['alg']);
    }

    public function test_a_verifier_outside_the_permitted_length_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Pkce::fromVerifier('too-short');
    }

    public function test_a_verifier_survives_being_put_away_and_brought_back(): void
    {
        $pkce = Pkce::generate();

        $this->assertSame($pkce->challenge(), Pkce::fromVerifier($pkce->verifier)->challenge());
    }

    public function test_refreshing_asks_for_nothing_but_a_new_token(): void
    {
        $fields = AuthorizationRequest::refresh(self::CLIENT, 'a-refresh-token', 'an-assertion');

        $this->assertSame('refresh_token', $fields['grant_type']);
        $this->assertSame('a-refresh-token', $fields['refresh_token']);
        $this->assertArrayNotHasKey('code', $fields);
    }

    /**
     * @param  array<string, Jwk>  $keys
     * @return array<string, mixed>
     */
    private function published(array $keys): array
    {
        return ClientMetadata::keySet($keys);
    }

    public function test_an_assertion_checks_out_against_the_keys_that_client_publishes(): void
    {
        $key = P256::generate();

        $claims = ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', $key),
            self::CLIENT,
            'https://home.test',
            $this->published(['atproto' => Jwk::forP256($key)]),
        );

        $this->assertSame(self::CLIENT, $claims['iss']);
    }

    /**
     * Without this, an assertion collected by one server could be replayed at
     * another as though the venue had addressed it.
     */
    public function test_an_assertion_addressed_elsewhere_is_refused(): void
    {
        $key = P256::generate();

        $this->expectException(RuntimeException::class);

        ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', $key),
            self::CLIENT,
            'https://somewhere.else',
            $this->published(['atproto' => Jwk::forP256($key)]),
        );
    }

    public function test_an_assertion_signed_by_a_key_that_client_does_not_publish_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', P256::generate()),
            self::CLIENT,
            'https://home.test',
            $this->published(['atproto' => Jwk::forP256(P256::generate())]),
        );
    }

    public function test_an_expired_assertion_is_refused(): void
    {
        $key = P256::generate();

        $this->expectException(RuntimeException::class);

        ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', $key, now: time() - 3600),
            self::CLIENT,
            'https://home.test',
            $this->published(['atproto' => Jwk::forP256($key)]),
        );
    }

    /**
     * A key set holds two keys during a rotation — the outgoing one and the
     * incoming one — so accepting only the first listed would break a venue at
     * exactly the moment it was being careful.
     */
    public function test_an_assertion_still_checks_out_while_that_client_is_rotating_keys(): void
    {
        $incoming = P256::generate();

        $claims = ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', $incoming),
            self::CLIENT,
            'https://home.test',
            $this->published([
                'outgoing' => Jwk::forP256(P256::generate()),
                'atproto' => Jwk::forP256($incoming),
            ]),
        );

        $this->assertSame(self::CLIENT, $claims['iss']);
    }

    public function test_a_client_publishing_no_keys_can_assert_nothing(): void
    {
        $this->expectException(RuntimeException::class);

        ClientAssertion::check(
            ClientAssertion::for(self::CLIENT, 'https://home.test', P256::generate()),
            self::CLIENT,
            'https://home.test',
            ['keys' => []],
        );
    }
}
