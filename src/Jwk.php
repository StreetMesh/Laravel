<?php

namespace StreetMesh\Protocol;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A public key written the way JOSE wants to read it.
 *
 * Everything else here identifies a key by a multikey — a compact string whose
 * prefix names the curve — because that is what a DID document publishes and
 * what one server hands another. DPoP is the exception, and unavoidably so: the
 * proof carries its own key inline, in JWK form, because the server receiving it
 * has never seen that key before and has nowhere to look it up.
 *
 * So this exists for the one place the rest of the world's spelling is required,
 * and does not spread: `Multikey` remains how a key is named anywhere a name is
 * enough.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7517
 */
final class Jwk
{
    /**
     * @param  string  $x  32 raw bytes
     * @param  string  $y  32 raw bytes
     */
    private function __construct(
        private readonly string $x,
        private readonly string $y,
    ) {}

    /**
     * The public half of a P-256 key, taken from OpenSSL rather than derived.
     *
     * A multikey holds the *compressed* point — the x coordinate and one bit
     * saying whether y is odd — and recovering y from that means solving the
     * curve equation. There is no need: whoever holds the key can ask OpenSSL
     * for both coordinates, which is exact and cannot be got subtly wrong.
     */
    public static function forP256(P256 $key): self
    {
        $openssl = openssl_pkey_get_private($key->secretKey());

        if ($openssl === false) {
            throw new RuntimeException('OpenSSL would not read that key.');
        }

        return self::fromDetails($openssl);
    }

    private static function fromDetails(OpenSSLAsymmetricKey $key): self
    {
        $details = openssl_pkey_get_details($key);

        if (! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('That key has no EC coordinates.');
        }

        return new self(
            str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
            str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        );
    }

    /**
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public function toArray(): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::encode($this->x),
            'y' => self::encode($this->y),
        ];
    }

    /**
     * The key's fingerprint, as everything that binds a token to it uses.
     *
     * An access token issued against a DPoP key names that key by thumbprint
     * rather than by carrying it, so this is what a client compares against to
     * confirm it was handed a token bound to the key it actually holds.
     *
     * The rule that makes it reproducible: only the members that define the key,
     * in lexicographic order, with no whitespace. Add a field or reorder one and
     * two implementations get different fingerprints for the same key — which is
     * the same class of trap as DAG-CBOR's length-first ordering, and worth the
     * same wariness.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7638
     */
    public function thumbprint(): string
    {
        $canonical = json_encode(
            ['crv' => 'P-256', 'kty' => 'EC', 'x' => self::encode($this->x), 'y' => self::encode($this->y)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return self::encode(hash('sha256', $canonical, binary: true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
