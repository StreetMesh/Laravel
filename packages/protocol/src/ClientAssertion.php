<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * How a venue proves it is itself, without ever having been given a password.
 *
 * A confidential client authenticates with a signature rather than a secret.
 * There is nothing to share out of band, nothing for a domicile to store, and
 * nothing that leaks if it does — the domicile checks this against the keys the
 * venue publishes, which it fetches at the moment it needs them.
 *
 * Which is the same idea as the client metadata document, one layer down: the
 * venue says who it is at a URL, and proves it by signing.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7523
 */
final class ClientAssertion
{
    public const TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * Short, because it is used once and immediately.
     *
     * The window is for clock drift between two servers run by different people
     * on different machines, with nothing synchronizing them — not for the
     * assertion to sit around in.
     */
    public const LIFETIME_SECONDS = 60;

    /**
     * @param  string  $clientId  the venue's metadata URL, which is its name
     * @param  string  $issuer  the authorization server being addressed
     * @param  string  $keyId  which published key this is signed with
     */
    public static function for(
        string $clientId,
        string $issuer,
        P256 $key,
        string $keyId = 'atproto',
        ?int $now = null,
    ): string {
        $now ??= time();

        return Jws::signWith(['kid' => $keyId], [
            /*
             * Both the issuer and the subject, because the venue is asserting
             * about itself. A client speaking for somebody else would put them
             * in `sub`, which is not what is happening here.
             */
            'iss' => $clientId,
            'sub' => $clientId,

            /*
             * Named so it cannot be replayed elsewhere. Without an audience, an
             * assertion collected by one server could be presented to another
             * as though the venue had addressed it.
             */
            'aud' => $issuer,

            'jti' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'iat' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
        ], $key);
    }

    /**
     * Check an assertion a venue sent us, against the keys it publishes.
     *
     * The keys arrive from the venue's own `jwks_uri`, fetched because this
     * request arrived rather than held in advance — which is the same shape as
     * everything else here: nothing agreed beforehand, everything looked up at
     * the moment it is needed.
     *
     * `$expectedAudience` is this server's own issuer, and checking it is not a
     * formality. Without it an assertion collected by one server could be
     * replayed at another as though the venue had addressed it.
     *
     * @param  array<string, mixed>  $keySet  the venue's JWKS, as fetched
     * @return array<string, mixed> the claims, once they are worth reading
     */
    public static function check(
        string $compact,
        string $expectedClientId,
        string $expectedAudience,
        array $keySet,
        ?int $now = null,
    ): array {
        $now ??= time();
        $claims = self::verifyAgainst($compact, $keySet);

        /*
         * A venue asserts about itself, so these are the same and both are the
         * client identifier. A mismatch means either a muddled client or one
         * speaking for somebody else, and neither is something to guess at.
         */
        foreach (['iss', 'sub'] as $claim) {
            if (($claims[$claim] ?? null) !== $expectedClientId) {
                throw new RuntimeException("That assertion's {$claim} is not the client it came from.");
            }
        }

        $audience = $claims['aud'] ?? null;
        $addressed = is_array($audience) ? $audience : [$audience];

        if (! in_array($expectedAudience, $addressed, strict: true)) {
            throw new RuntimeException('That assertion was addressed to somebody else.');
        }

        $expires = $claims['exp'] ?? null;

        if (! is_int($expires) || $expires <= $now) {
            throw new RuntimeException('That assertion has expired.');
        }

        // A long-lived assertion is a password with a date on it. The window is
        // for clock drift, not for the thing to be kept and reused.
        if ($expires - $now > self::LIFETIME_SECONDS * 10) {
            throw new RuntimeException('That assertion is good for far too long.');
        }

        if (! is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw new RuntimeException('That assertion has no identifier, so a replay could not be refused.');
        }

        return $claims;
    }

    /**
     * Whichever published key verifies it, preferring the one it names.
     *
     * A key set holds more than one during a rotation — the outgoing key and
     * the incoming one — so refusing everything but the first would break a
     * venue exactly while it was being careful.
     *
     * @param  array<string, mixed>  $keySet
     * @return array<string, mixed>
     */
    private static function verifyAgainst(string $compact, array $keySet): array
    {
        $keys = array_values(array_filter((array) ($keySet['keys'] ?? []), is_array(...)));

        if ($keys === []) {
            throw new RuntimeException('That client publishes no keys, so nothing it signs can be checked.');
        }

        $named = null;

        $header = json_decode(
            (string) base64_decode(strtr(explode('.', $compact)[0], '-_', '+/'), true),
            true,
        );

        if (is_array($header) && is_string($header['kid'] ?? null)) {
            $named = $header['kid'];
        }

        usort($keys, fn (array $a, array $b): int => (int) (($b['kid'] ?? null) === $named) <=> (int) (($a['kid'] ?? null) === $named));

        foreach ($keys as $key) {
            try {
                return Jws::verify($compact, Jwk::fromArray($key)->multikey());
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException('That assertion does not verify against any key that client publishes.');
    }
}
