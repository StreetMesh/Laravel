<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * Proof that whoever is holding this token is whoever it was issued to.
 *
 * A bearer token is a password: anything that gets hold of one can spend it —
 * a log file, a proxy, a browser extension, a server that keeps request
 * headers. The token is the whole of the claim, so a copy of the token is a
 * copy of the authority.
 *
 * DPoP binds the token to a key instead. Every request carries a short-lived
 * signature over *that* method and *that* URL, made with a key the token names
 * by fingerprint. A stolen token is then worth nothing without the key, which
 * never leaves the venue.
 *
 * This is not optional in this profile — it is required for every client and
 * every request, which is one of the two reasons Laravel Passport cannot be the
 * foundation here.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9449
 */
final class Dpop
{
    public const TYPE = 'dpop+jwt';

    /**
     * How far out of step with the server we tolerate being.
     *
     * A proof is only good for the moment it is made, so both ends have to
     * agree roughly what time it is. This matters here rather than being
     * boilerplate: a venue and a domicile are run by different people on
     * different machines, and nothing anywhere synchronizes them.
     */
    public const LIFETIME_SECONDS = 30;

    /**
     * One proof, good for one request.
     *
     * @param  string  $method  the HTTP method, uppercase
     * @param  string  $url  where the request is going
     * @param  string|null  $nonce  whatever the server last asked to be echoed
     * @param  string|null  $accessToken  present once there is one to bind to
     */
    public static function proof(
        P256 $key,
        string $method,
        string $url,
        ?string $nonce = null,
        ?string $accessToken = null,
        ?int $now = null,
    ): string {
        $claims = [
            /*
             * Unique per proof, so a server can refuse a replay. Random rather
             * than a counter because there is no shared state to count in.
             */
            'jti' => self::encode(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => self::target($url),
            'iat' => $now ?? time(),
        ];

        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        /*
         * Binds the proof to one specific token. Without it a proof made for a
         * request could be lifted and reused alongside a different token, which
         * would undo most of what this is for.
         */
        if ($accessToken !== null) {
            $claims['ath'] = self::encode(hash('sha256', $accessToken, binary: true));
        }

        return Jws::signWith(
            ['typ' => self::TYPE, 'jwk' => Jwk::forP256($key)->toArray()],
            $claims,
            $key,
        );
    }

    /**
     * Check a proof somebody sent us, and say which key made it.
     *
     * The returned thumbprint is the whole point. It is what a token is bound
     * to, so a server issuing one records this, and a server accepting one
     * compares this against what the token says. Everything else here is a
     * precondition for that answer being worth anything.
     *
     * `jti` is not checked, and cannot be: refusing a replay means remembering
     * every identifier seen inside the window, which is storage this layer does
     * not have. The caller has to do it, and the caller is the only one who can.
     *
     * @param  string  $compact  the DPoP header, as it arrived
     * @param  string|null  $nonce  what we last told them to echo, if we did
     * @param  string|null  $accessToken  the token this accompanies, if any
     * @return string the thumbprint of the key that signed it
     */
    public static function check(
        string $compact,
        string $method,
        string $url,
        ?string $nonce = null,
        ?string $accessToken = null,
        ?int $now = null,
    ): string {
        $now ??= time();
        $header = self::header($compact);

        if (($header['typ'] ?? null) !== self::TYPE) {
            throw new RuntimeException('That is not a DPoP proof.');
        }

        if (! is_array($header['jwk'] ?? null)) {
            throw new RuntimeException('That proof carries no key, so nothing can be checked against it.');
        }

        /*
         * A private half here would mean somebody has sent us their signing key
         * by mistake. Refused rather than ignored, because carrying on would
         * mean accepting a proof whose maker has just been compromised.
         */
        if (isset($header['jwk']['d'])) {
            throw new RuntimeException('That proof carries a private key. Refusing it.');
        }

        $key = Jwk::fromArray($header['jwk']);

        // Verified before any claim inside it is read, since until this passes
        // the claims are just something a stranger wrote.
        $claims = Jws::verify($compact, $key->multikey());

        if (($claims['htm'] ?? null) !== strtoupper($method)) {
            throw new RuntimeException('That proof was made for a different method.');
        }

        if (($claims['htu'] ?? null) !== self::target($url)) {
            throw new RuntimeException('That proof was made for a different URL.');
        }

        $issued = $claims['iat'] ?? null;

        if (! is_int($issued) || abs($now - $issued) > self::LIFETIME_SECONDS) {
            throw new RuntimeException('That proof is not from around now.');
        }

        if ($nonce !== null && ($claims['nonce'] ?? null) !== $nonce) {
            throw new RuntimeException('That proof does not carry the nonce we asked for.');
        }

        /*
         * Binds proof to token. Without it, a proof made for one request could
         * be lifted and presented alongside a different token entirely.
         */
        if ($accessToken !== null) {
            $expected = self::encode(hash('sha256', $accessToken, binary: true));

            if (($claims['ath'] ?? null) !== $expected) {
                throw new RuntimeException('That proof was not made for this token.');
            }
        }

        if (! is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw new RuntimeException('That proof has no identifier, so a replay of it could not be refused.');
        }

        return $key->thumbprint();
    }

    /**
     * @return array<string, mixed>
     */
    private static function header(string $compact): array
    {
        $parts = explode('.', $compact);

        if (count($parts) !== 3) {
            throw new RuntimeException('That is not a compact JWS.');
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('That proof has an unreadable header.');
        }

        $header = json_decode($decoded, true);

        return is_array($header) ? $header : throw new RuntimeException('That proof has an unreadable header.');
    }

    /**
     * The URL a proof commits to: no query, no fragment.
     *
     * Both are excluded by the specification, and the reason is worth keeping:
     * a query string is where the parts of a request most likely to be rewritten
     * in transit live, and a proof that broke whenever a gateway reordered
     * parameters would be a proof nobody could use.
     */
    public static function target(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("[{$url}] is not a URL a proof can name.");
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.($parts['path'] ?? '');
    }

    /**
     * The nonce a server is asking to be echoed, if it is asking.
     *
     * Server-issued nonces are mandatory here and rotate every few minutes, so
     * being told to use a new one is an ordinary event in the middle of a
     * working conversation rather than a failure. A client that treats it as an
     * error works until the first rotation and then stops.
     *
     * @param  array<string, array<int, string>|string>  $headers
     */
    public static function nonceFrom(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'dpop-nonce') {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
