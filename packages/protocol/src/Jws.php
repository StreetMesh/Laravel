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
    public static function sign(array $claims, SigningKey $key, string $keyId): string
    {
        return self::signWith(['kid' => $keyId], $claims, $key);
    }

    /**
     * The same, for a header this scheme does not get to decide.
     *
     * A record says which key signed it and nothing more, so `sign()` can build
     * the whole header itself. Other things built on JWS cannot: a DPoP proof
     * carries its own `typ` and hands over its public key inline, because the
     * server receiving it has never seen that key and has nowhere to look it up.
     *
     * `alg` is not overridable and comes from the key, because a document
     * choosing how it will be checked is the classic JOSE footgun — the same
     * reason `keyId()` below refuses an algorithm it does not recognize.
     *
     * It also stays *first*, which is not cosmetic: the signature covers these
     * bytes exactly as encoded, so moving a member changes every signature this
     * package has ever produced. The conformance vectors caught that when this
     * method was extracted, which is the entire reason they exist.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function signWith(array $header, array $claims, SigningKey $key): string
    {
        unset($header['alg']);

        $header = self::encode(self::json(['alg' => $key->algorithm(), ...$header]));
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

        /*
         * Pinned to what the key itself says, never to what the document claims.
         * Accepting an algorithm named in an unverified header is the classic
         * JOSE footgun — it lets a document choose how it will be checked.
         */
        if (! in_array($header['alg'] ?? null, ['EdDSA', 'ES256', 'ES256K'], strict: true)) {
            throw new RuntimeException('That signature names an algorithm this does not accept.');
        }

        return $header['kid'] ?? throw new RuntimeException('That document names no key.');
    }

    /**
     * @param  string  $multikey  the key as its owner published it, curve and all
     * @return array<string, mixed> the claims, once they are worth reading
     */
    public static function verify(string $compact, string $multikey): array
    {
        [$header, $payload, $signature] = self::parts($compact);

        $raw = base64_decode(strtr($signature, '-_', '+/'), true);

        if ($raw === false || ! Signature::verify($multikey, $header.'.'.$payload, $raw)) {
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
