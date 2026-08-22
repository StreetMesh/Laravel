<?php

namespace StreetMesh\Server\Protocol\Permissions;

use RuntimeException;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\Pkce;

/**
 * The domicile's side of granting permission.
 *
 * A venue asks, a resident answers, and this is what turns the second into
 * something the venue can use. Four stages, each of which replaces the secret
 * the last one produced: a pushed request becomes a code, a code becomes a
 * token, a token is refreshed.
 *
 * What a domicile never has is a prior relationship with the venue. It has
 * never met it, holds no secret it shares with it, and stores nothing about it
 * beyond the name — so every claim the venue makes is checked against documents
 * fetched at the moment the request arrives.
 */
final class Permissions
{
    /**
     * A pushed request is good for long enough to redirect a browser.
     */
    public const REQUEST_SECONDS = 300;

    /**
     * A code is spent immediately by a server that is already waiting on it.
     */
    public const CODE_SECONDS = 60;

    /**
     * Well under the half hour this profile allows, because refreshing is the
     * ordinary path rather than an exception and a short window costs nothing.
     */
    public const TOKEN_SECONDS = 900;

    public function __construct(
        private readonly Network $network,
        private readonly Spent $spent,
    ) {}

    /**
     * Stage one: a venue pushes what it wants, and gets back a handle.
     *
     * Nothing is decided here and nobody has agreed to anything — this only
     * establishes that the venue is who it says, and remembers what it asked so
     * the person can be shown it.
     *
     * @param  array<string, mixed>  $fields
     */
    public function push(array $fields, string $issuer, string $thumbprint): Permission
    {
        $clientId = (string) ($fields['client_id'] ?? '');
        $metadata = $this->clientMetadata($clientId);

        $this->assertion(
            (string) ($fields['client_assertion'] ?? ''),
            $clientId,
            $issuer,
            (string) ($metadata['jwks_uri'] ?? ''),
        );

        /*
         * Where a person is sent afterwards has to be one of the addresses the
         * venue published. Without this, anybody could ask for a code and have
         * it delivered to an address of their choosing.
         */
        $redirect = (string) ($fields['redirect_uri'] ?? '');

        if (! in_array($redirect, (array) ($metadata['redirect_uris'] ?? []), strict: true)) {
            throw new RuntimeException('That redirect address is not one this client publishes.');
        }

        if (($fields['code_challenge_method'] ?? null) !== Pkce::METHOD) {
            throw new RuntimeException('Only S256 challenges are accepted here.');
        }

        $challenge = (string) ($fields['code_challenge'] ?? '');

        if ($challenge === '') {
            throw new RuntimeException('A request without a challenge cannot be redeemed safely.');
        }

        return Permission::create([
            'client_id' => $clientId,
            'request_uri' => 'urn:ietf:params:oauth:request_uri:'.self::secret(),
            'scope' => (string) ($fields['scope'] ?? ClientMetadata::BASE_SCOPE),
            'redirect_uri' => $redirect,
            'state' => $fields['state'] ?? null,
            'code_challenge' => $challenge,
            'thumbprint' => $thumbprint,
            'request_expires_at' => now()->addSeconds(self::REQUEST_SECONDS),
        ]);
    }

    /**
     * What the person is being asked to agree to, found from the handle.
     *
     * Throws, for callers that cannot go on without one. A screen somebody is
     * looking at is not one of those — see `awaiting`.
     */
    public function pending(string $requestUri): Permission
    {
        return $this->awaiting($requestUri)
            ?? throw new RuntimeException('That request has expired or was already answered.');
    }

    /**
     * The same lookup, for somewhere that has to cope with the answer being no.
     *
     * Nothing here is a fault. A request lasts a few minutes and is emptied the
     * moment it is answered, so somebody who left the screen open over lunch,
     * or reloaded it after deciding, is asking a reasonable question and
     * getting the true answer. What they must not get is a stack trace.
     */
    public function awaiting(string $requestUri): ?Permission
    {
        return Permission::query()
            ->where('request_uri', $requestUri)
            ->where('request_expires_at', '>', now())
            ->whereNull('approved_at')
            ->first();
    }

    /**
     * Stage two: somebody says yes, and gets sent back with a code.
     *
     * The code is returned rather than stored in the clear, and this is the
     * only moment it exists in readable form.
     */
    public function approve(Permission $permission, string $did): string
    {
        $code = self::secret();

        $permission->update([
            'did' => $did,
            'approved_at' => now(),
            'request_uri' => null,
            'code_hash' => Permission::fingerprint($code),
            'code_expires_at' => now()->addSeconds(self::CODE_SECONDS),
        ]);

        return $code;
    }

    /**
     * Stage three: the code is traded for a token.
     *
     * The verifier appears here for the first time and is checked against the
     * challenge pushed at the start — which is what makes an intercepted code
     * worthless to whoever intercepted it.
     *
     * @param  array<string, mixed>  $fields
     * @return array{permission: Permission, access: string, refresh: string}
     */
    public function redeem(array $fields, string $issuer, string $thumbprint): array
    {
        $clientId = (string) ($fields['client_id'] ?? '');

        $permission = Permission::query()
            ->where('code_hash', Permission::fingerprint((string) ($fields['code'] ?? '')))
            ->where('code_expires_at', '>', now())
            ->first();

        if ($permission === null || ! $permission->isLive()) {
            throw new RuntimeException('That code is not one this server is holding.');
        }

        if ($permission->client_id !== $clientId) {
            throw new RuntimeException('That code was issued to a different client.');
        }

        /*
         * The token is bound to the key that redeemed it, and that key must be
         * the one which pushed the request. Otherwise a stolen code could be
         * spent by somebody who simply brought their own.
         */
        if ($permission->thumbprint !== $thumbprint) {
            throw new RuntimeException('That code was asked for by a different key.');
        }

        $metadata = $this->clientMetadata($clientId);

        $this->assertion(
            (string) ($fields['client_assertion'] ?? ''),
            $clientId,
            $issuer,
            (string) ($metadata['jwks_uri'] ?? ''),
        );

        $verifier = (string) ($fields['code_verifier'] ?? '');

        if (! hash_equals($permission->code_challenge, Pkce::fromVerifier($verifier)->challenge())) {
            throw new RuntimeException('That verifier does not match the challenge this began with.');
        }

        return $this->issue($permission);
    }

    /**
     * Stage four, and the ordinary one: a fresh token before the last expires.
     *
     * The refresh token is replaced as it is used, so a copy of one that has
     * already been spent is worth nothing.
     *
     * @param  array<string, mixed>  $fields
     * @return array{permission: Permission, access: string, refresh: string}
     */
    public function refresh(array $fields, string $issuer, string $thumbprint): array
    {
        $clientId = (string) ($fields['client_id'] ?? '');

        $permission = Permission::query()
            ->where('refresh_hash', Permission::fingerprint((string) ($fields['refresh_token'] ?? '')))
            ->first();

        if ($permission === null || ! $permission->isLive()) {
            throw new RuntimeException('That refresh token is not one this server is holding.');
        }

        if ($permission->client_id !== $clientId || $permission->thumbprint !== $thumbprint) {
            throw new RuntimeException('That refresh token belongs to a different client or key.');
        }

        $metadata = $this->clientMetadata($clientId);

        $this->assertion(
            (string) ($fields['client_assertion'] ?? ''),
            $clientId,
            $issuer,
            (string) ($metadata['jwks_uri'] ?? ''),
        );

        return $this->issue($permission);
    }

    /**
     * A token being presented, and whether it is anybody's.
     *
     * The thumbprint has to match the proof that came with it: a token on its
     * own is not enough, which is the whole of what DPoP buys.
     */
    public function holder(string $token, string $thumbprint): Permission
    {
        $permission = Permission::query()
            ->where('token_hash', Permission::fingerprint($token))
            ->where('token_expires_at', '>', now())
            ->first();

        if ($permission === null || ! $permission->isLive()) {
            throw new RuntimeException('That token is not one this server is holding.');
        }

        if (! hash_equals((string) $permission->thumbprint, $thumbprint)) {
            throw new RuntimeException('That token was issued to a different key.');
        }

        return $permission;
    }

    /**
     * Taking it back. It must refuse afterwards rather than merely lapse, so
     * everything is cleared rather than left to expire.
     */
    public function withdraw(Permission $permission): void
    {
        $permission->update([
            'withdrawn_at' => now(),
            'code_hash' => null,
            'token_hash' => null,
            'refresh_hash' => null,
            'request_uri' => null,
        ]);
    }

    /**
     * @return array{permission: Permission, access: string, refresh: string}
     */
    private function issue(Permission $permission): array
    {
        $access = self::secret();
        $refresh = self::secret();

        $permission->update([
            'code_hash' => null,
            'token_hash' => Permission::fingerprint($access),
            'refresh_hash' => Permission::fingerprint($refresh),
            'token_expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
        ]);

        return ['permission' => $permission, 'access' => $access, 'refresh' => $refresh];
    }

    /**
     * The venue's own description of itself, fetched now rather than remembered.
     *
     * @return array<string, mixed>
     */
    private function clientMetadata(string $clientId): array
    {
        $document = $this->fetch($clientId);

        if ($document === null) {
            throw new RuntimeException("[{$clientId}] serves no client metadata.");
        }

        /*
         * The document has to agree about its own name. Without this a venue
         * could serve a document claiming to be some other client and borrow
         * whatever that one had been trusted with.
         */
        if (($document['client_id'] ?? null) !== $clientId) {
            throw new RuntimeException('That client document names a different client.');
        }

        return $document;
    }

    private function assertion(string $compact, string $clientId, string $issuer, string $jwksUri): void
    {
        if ($jwksUri === '') {
            throw new RuntimeException('That client publishes no keys, so nothing it signs can be checked.');
        }

        $keys = $this->fetch($jwksUri);

        if ($keys === null) {
            throw new RuntimeException('That client\'s keys could not be fetched.');
        }

        $claims = ClientAssertion::check($compact, $clientId, $issuer, $keys);

        // Single use. Without this, an assertion overheard once could be
        // presented again for as long as it remained unexpired.
        $this->spent->record((string) $claims['jti'], (int) $claims['exp']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $url): ?array
    {
        $body = $this->network->get($url);

        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
