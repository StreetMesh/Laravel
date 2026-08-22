<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;

class DpopTest extends TestCase
{
    private function key(): P256
    {
        return P256::generate();
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function parse(string $compact): array
    {
        [$header, $payload] = explode('.', $compact);

        return [
            json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true),
            json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true),
        ];
    }

    /**
     * The header is the whole difference from every other signature here: the
     * key travels inside it, because the server has never seen it before.
     */
    public function test_a_proof_carries_its_own_key(): void
    {
        $key = $this->key();

        [$header] = $this->parse(Dpop::proof($key, 'POST', 'https://auth.example/oauth/par'));

        $this->assertSame('dpop+jwt', $header['typ']);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame(Jwk::forP256($key)->toArray(), $header['jwk']);
    }

    public function test_a_proof_names_the_request_it_was_made_for(): void
    {
        [, $claims] = $this->parse(
            Dpop::proof($this->key(), 'post', 'https://auth.example/oauth/par')
        );

        $this->assertSame('POST', $claims['htm']);
        $this->assertSame('https://auth.example/oauth/par', $claims['htu']);
    }

    /**
     * A query string is where the parts of a request most likely to be
     * rewritten in transit live. A proof that broke when a gateway reordered
     * parameters would be a proof nobody could use.
     */
    public function test_the_url_a_proof_commits_to_drops_query_and_fragment(): void
    {
        $this->assertSame(
            'https://auth.example/oauth/token',
            Dpop::target('https://auth.example/oauth/token?state=abc#part'),
        );

        $this->assertSame(
            'https://auth.example:8443/oauth/token',
            Dpop::target('https://auth.example:8443/oauth/token'),
        );
    }

    public function test_something_that_is_not_a_url_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Dpop::target('/oauth/token');
    }

    /**
     * Without a unique identifier per proof a server has no way to refuse a
     * replay, which is most of what this is for.
     */
    public function test_every_proof_is_its_own(): void
    {
        $key = $this->key();
        $identifiers = [];

        foreach (range(1, 20) as $ignored) {
            [, $claims] = $this->parse(Dpop::proof($key, 'POST', 'https://auth.example/oauth/par'));
            $identifiers[] = $claims['jti'];
        }

        $this->assertCount(20, array_unique($identifiers));
    }

    public function test_a_nonce_is_carried_only_once_there_is_one(): void
    {
        [, $without] = $this->parse(Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par'));
        [, $with] = $this->parse(Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par', nonce: 'abc'));

        $this->assertArrayNotHasKey('nonce', $without);
        $this->assertSame('abc', $with['nonce']);
    }

    /**
     * Binding the proof to one token. Without it a proof could be lifted and
     * reused alongside a different token.
     */
    public function test_a_proof_binds_to_the_token_it_accompanies(): void
    {
        [, $claims] = $this->parse(
            Dpop::proof($this->key(), 'GET', 'https://pds.example/xrpc/x', accessToken: 'a-token')
        );

        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', 'a-token', binary: true)), '+/', '-_'), '='),
            $claims['ath'],
        );
    }

    /**
     * Signed with the key it advertises, which is the only thing making any of
     * the above worth anything.
     */
    public function test_a_proof_verifies_against_the_key_inside_it(): void
    {
        $key = $this->key();
        $compact = Dpop::proof($key, 'POST', 'https://auth.example/oauth/par');

        [$header, $payload, $signature] = explode('.', $compact);

        $this->assertTrue($key->verify(
            $header.'.'.$payload,
            (string) base64_decode(strtr($signature, '-_', '+/'), true),
        ));
    }

    /**
     * Nonces rotate every few minutes, so being handed a new one mid-conversation
     * is ordinary rather than a failure. Header names arrive in whatever case
     * the server and the client library between them decided on.
     */
    public function test_a_nonce_is_read_whatever_case_it_arrives_in(): void
    {
        $this->assertSame('abc', Dpop::nonceFrom(['DPoP-Nonce' => 'abc']));
        $this->assertSame('abc', Dpop::nonceFrom(['dpop-nonce' => ['abc']]));
        $this->assertNull(Dpop::nonceFrom(['Content-Type' => 'application/json']));
    }

    /**
     * The answer a server actually wants: which key made this. It is what a
     * token gets bound to, so everything else in the check is a precondition
     * for this being worth anything.
     */
    public function test_checking_a_proof_says_which_key_made_it(): void
    {
        $key = $this->key();

        $this->assertSame(
            Jwk::forP256($key)->thumbprint(),
            Dpop::check(Dpop::proof($key, 'POST', 'https://auth.example/oauth/par'), 'POST', 'https://auth.example/oauth/par'),
        );
    }

    public function test_a_proof_made_for_another_request_is_refused(): void
    {
        $proof = Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par');

        foreach ([['GET', 'https://auth.example/oauth/par'], ['POST', 'https://auth.example/oauth/token']] as [$method, $url]) {
            try {
                Dpop::check($proof, $method, $url);
                $this->fail("a proof for another request was accepted: {$method} {$url}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The signature is checked before any claim is read, because until it
     * passes the claims are just something a stranger wrote.
     */
    public function test_a_proof_whose_claims_were_altered_is_refused(): void
    {
        [$header, $payload, $signature] = explode('.', Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par'));

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        $claims['htu'] = 'https://somewhere.else/oauth/par';

        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);

        Dpop::check($header.'.'.$forged.'.'.$signature, 'POST', 'https://somewhere.else/oauth/par');
    }

    /**
     * A key swapped for one the sender holds would let anybody present a proof
     * for anybody's token — which is the attack this whole mechanism exists to
     * stop, so it is worth an explicit test rather than trusting the library.
     */
    public function test_a_proof_resigned_with_another_key_does_not_pass_as_the_first(): void
    {
        $mine = Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par');
        $theirs = Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par');

        [$header] = explode('.', $mine);
        [, $payload, $signature] = explode('.', $theirs);

        $this->expectException(RuntimeException::class);

        Dpop::check($header.'.'.$payload.'.'.$signature, 'POST', 'https://auth.example/oauth/par');
    }

    public function test_a_stale_proof_is_refused(): void
    {
        $old = Dpop::proof($this->key(), 'POST', 'https://auth.example/oauth/par', now: time() - 3600);

        $this->expectException(RuntimeException::class);

        Dpop::check($old, 'POST', 'https://auth.example/oauth/par');
    }

    public function test_a_proof_without_the_nonce_we_asked_for_is_refused(): void
    {
        $key = $this->key();
        $url = 'https://auth.example/oauth/par';

        $this->assertSame(
            Jwk::forP256($key)->thumbprint(),
            Dpop::check(Dpop::proof($key, 'POST', $url, nonce: 'abc'), 'POST', $url, nonce: 'abc'),
        );

        $this->expectException(RuntimeException::class);

        Dpop::check(Dpop::proof($key, 'POST', $url), 'POST', $url, nonce: 'abc');
    }

    public function test_a_proof_made_for_a_different_token_is_refused(): void
    {
        $key = $this->key();
        $url = 'https://pds.example/xrpc/x';

        $this->expectException(RuntimeException::class);

        Dpop::check(
            Dpop::proof($key, 'GET', $url, accessToken: 'one-token'),
            'GET',
            $url,
            accessToken: 'another-token',
        );
    }

    /**
     * Somebody sending their signing key by mistake is a compromise in
     * progress, and carrying on would mean accepting a proof from a key that
     * has just been given away.
     */
    public function test_a_proof_carrying_a_private_key_is_refused(): void
    {
        $key = $this->key();
        $url = 'https://auth.example/oauth/par';

        [$header, $payload, $signature] = explode('.', Dpop::proof($key, 'POST', $url));

        $decoded = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true);
        $decoded['jwk']['d'] = 'a-private-scalar';

        $tampered = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);

        Dpop::check($tampered.'.'.$payload.'.'.$signature, 'POST', $url);
    }
}
