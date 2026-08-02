<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;

class JwkTest extends TestCase
{
    /**
     * A fixed key and the fingerprint it must produce.
     *
     * Derived rather than written down. The expected value came from an
     * independent implementation in another language computing RFC 7638 from
     * the same JWK, and the same script confirms the specification's own worked
     * example first — so what is frozen here is an agreement between two
     * implementations and the RFC, not an author's belief about one.
     *
     * That distinction has already cost this project once: hand-written vectors
     * were wrong in exactly the way a plausible string is wrong, which is
     * silently and forever.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7638#section-3.2
     */
    public function test_a_known_key_fingerprints_to_a_known_value(): void
    {
        $canonical = json_encode([
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => '0A-Iqvni5xQA1Fp_KkbEDPebc1E0VrVJVBc9_RP6Ivg',
            'y' => '9MvbdAmGWDJGx7q2nIK_UO_5_lYGxa5WC-Vn5mMhI-8',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(
            'E4BPO1xt-uIqjw-GX_mdaq6dzFllR_d9138nFyI0mKw',
            rtrim(strtr(base64_encode(hash('sha256', $canonical, binary: true)), '+/', '-_'), '='),
        );
    }

    /**
     * The members and their order are the whole of the rule, and getting either
     * wrong produces a fingerprint that is perfectly well-formed and matches
     * nothing — the same class of trap as DAG-CBOR's length-first key ordering.
     */
    public function test_the_fingerprint_is_taken_over_exactly_the_defining_members(): void
    {
        $jwk = Jwk::forP256(P256::generate());
        $members = $jwk->toArray();

        $canonical = json_encode([
            'crv' => $members['crv'],
            'kty' => $members['kty'],
            'x' => $members['x'],
            'y' => $members['y'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $canonical, binary: true)), '+/', '-_'), '='),
            $jwk->thumbprint(),
        );
    }

    public function test_a_key_gives_both_coordinates_at_full_width(): void
    {
        $jwk = Jwk::forP256(P256::generate())->toArray();

        $this->assertSame('EC', $jwk['kty']);
        $this->assertSame('P-256', $jwk['crv']);

        foreach (['x', 'y'] as $coordinate) {
            $this->assertSame(
                32,
                strlen((string) base64_decode(strtr($jwk[$coordinate], '-_', '+/'), true)),
                "{$coordinate} should be 32 bytes even when it has leading zeros",
            );
        }
    }

    public function test_the_same_key_always_fingerprints_the_same(): void
    {
        $key = P256::generate();

        $this->assertSame(Jwk::forP256($key)->thumbprint(), Jwk::forP256($key)->thumbprint());
        $this->assertNotSame(Jwk::forP256($key)->thumbprint(), Jwk::forP256(P256::generate())->thumbprint());
    }

    /**
     * The multikey is what a DID document publishes; the JWK is what a DPoP
     * proof carries. They describe one key, so the x coordinate inside each has
     * to be the same 32 bytes — a compressed point is that x with a parity byte
     * in front of it.
     */
    public function test_it_describes_the_same_key_the_multikey_does(): void
    {
        $key = P256::generate();
        $jwk = Jwk::forP256($key)->toArray();

        $this->assertSame(
            substr($key->publicKey(), 1),
            (string) base64_decode(strtr($jwk['x'], '-_', '+/'), true),
        );
    }
}
