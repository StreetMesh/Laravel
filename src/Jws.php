<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * A record signed as the bytes it travels as.
 *
 * The whole of what went wrong with our own scheme is absent here by
 * construction. We signed a PHP array, sent JSON, and the verifier re-derived
 * the bytes from what it decoded — so anything between the two that touched the
 * document broke the signature, and one thing did. A JWS signs the encoded
 * payload itself, so there is nothing to re-derive and no canonicalization rule
 * for two implementations to disagree about.
 *
 * `RoomTicket` already worked this way, with a comment explaining why. This is
 * that idea in the format the rest of the world already has libraries for.
 */
final class Jws
{
    /**
     * @param  array<string, mixed>  $claims
     * @param  string  $keyId  a DID verification method, e.g. did:web:chess.test#streetmesh
     */
    public static function sign(array $claims, Ed25519 $key, string $keyId): string
    {
        $header = self::encode(self::json(['alg' => 'EdDSA', 'kid' => $keyId]));
        $payload = self::encode(self::json($claims));

        $signature = $key->sign($header.'.'.$payload);

        return $header.'.'.$payload.'.'.self::encode($signature);
    }

    /**
     * Which key was this signed with, before we have any way to check it.
     *
     * Reading the header of an unverified document is not trusting it: the `kid`
     * says where to look, and the signature check that follows is what decides
     * whether the answer counts.
     */
    public static function keyId(string $compact): string
    {
        $header = self::decodeJson(self::part($compact, 0));

        if (($header['alg'] ?? null) !== 'EdDSA') {
            throw new RuntimeException('Only EdDSA signatures are accepted.');
        }

        return $header['kid'] ?? throw new RuntimeException('That document names no key.');
    }

    /**
     * @param  string  $publicKey  base64 Ed25519
     * @return array<string, mixed> the claims, once they are worth reading
     */
    public static function verify(string $compact, string $publicKey): array
    {
        [$header, $payload, $signature] = self::parts($compact);

        if (! Ed25519::verify($header.'.'.$payload, $signature, $publicKey)) {
            throw new RuntimeException('That document does not verify against that key.');
        }

        return self::decodeJson($payload);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parts(string $compact): array
    {
        $parts = explode('.', $compact);

        if (count($parts) !== 3) {
            throw new RuntimeException('That is not a compact JWS.');
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    private static function part(string $compact, int $index): string
    {
        return self::parts($compact)[$index];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $encoded): array
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('That segment is not base64url.');
        }

        return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
