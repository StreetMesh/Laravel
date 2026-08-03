<?php

namespace StreetMesh\Protocol;

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
}
