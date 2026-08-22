<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;

/**
 * What a venue says it is, and where it says it.
 *
 * This document is the whole of client registration. There is no sign-up call,
 * no secret handed over, no record kept on either side — a venue publishes JSON
 * at a URL, and **that URL is its identifier**. A domicile that has never heard
 * of the venue fetches it at the moment it is first asked for something.
 *
 * The consequence worth sitting with: two servers run by strangers need to agree
 * on nothing in advance. Our prototype built a registration endpoint, a table to
 * remember the result, and a handshake to keep the two in step; the standard's
 * answer deletes all three, and deletes with them every way that arrangement
 * could drift out of sync.
 *
 * A venue is a web service holding a key it never gives away, which makes it a
 * confidential client — the kind that authenticates with a signature rather than
 * a shared secret, and is trusted with longer sessions in return.
 *
 * @see https://atproto.com/specs/oauth
 */
final class ClientMetadata
{
    /**
     * The one scope every session here carries.
     *
     * It is the claim to be following this profile at all. Anything of ours
     * beyond it is an extension and has to be named as one — a scope invented
     * locally is a word no other server on the network knows.
     */
    public const BASE_SCOPE = 'atproto';

    /**
     * @param  array<int, string>  $redirectUris
     * @param  array<int, string>  $scopes
     */
    private function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly string $clientUri,
        public readonly array $redirectUris,
        public readonly array $scopes,
        public readonly string $jwksUri,
        public readonly string $algorithm,
    ) {}

    /**
     * @param  array<int, string>  $redirectUris  where a person comes back to
     * @param  array<int, string>  $scopes  beyond `atproto`, which is always included
     */
    public static function forVenue(
        string $clientId,
        string $clientName,
        string $clientUri,
        array $redirectUris,
        string $jwksUri,
        array $scopes = [],
        string $algorithm = 'ES256',
    ): self {
        foreach ([$clientId, $clientUri, $jwksUri, ...$redirectUris] as $url) {
            self::mustBeHttps($url);
        }

        if ($redirectUris === []) {
            throw new InvalidArgumentException('A client with nowhere to come back to cannot be authorized.');
        }

        return new self(
            clientId: $clientId,
            clientName: $clientName,
            clientUri: $clientUri,
            redirectUris: array_values($redirectUris),
            scopes: array_values(array_unique([self::BASE_SCOPE, ...$scopes])),
            jwksUri: $jwksUri,
            algorithm: $algorithm,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            /*
             * Repeated inside the document it is served from, and checked
             * against where it was fetched. Without that a document could claim
             * to be some other client and borrow whatever that client had been
             * trusted with.
             */
            'client_id' => $this->clientId,

            'application_type' => 'web',
            'client_name' => $this->clientName,
            'client_uri' => $this->clientUri,
            'redirect_uris' => $this->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => implode(' ', $this->scopes),

            /*
             * Not a preference. A client declaring anything else here is asking
             * for bearer tokens, and this profile does not issue them.
             */
            'dpop_bound_access_tokens' => true,

            /*
             * A signature rather than a shared secret, which is what makes this
             * a confidential client. There is nothing for a domicile to store
             * and nothing that leaks if it does.
             */
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => $this->algorithm,
            'jwks_uri' => $this->jwksUri,
        ];
    }

    /**
     * The venue's public keys, for a domicile checking what the venue signed.
     *
     * Published at a URL of their own rather than inline, so that rotating a key
     * is replacing the contents of a document instead of reissuing the identity
     * that names it.
     *
     * @param  array<string, Jwk>  $keys  keyed by the name each is known by
     * @return array<string, mixed>
     */
    public static function keySet(array $keys, string $algorithm = 'ES256'): array
    {
        $jwks = [];

        foreach ($keys as $name => $key) {
            $jwks[] = [
                ...$key->toArray(),
                'kid' => $name,
                'use' => 'sig',
                'alg' => $algorithm,
            ];
        }

        return ['keys' => $jwks];
    }

    /**
     * Everything here is fetched by a stranger over the open web, so a document
     * that named anything reachable in the clear would be inviting whoever sits
     * in the middle to answer for us.
     */
    private static function mustBeHttps(string $url): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException("[{$url}] is not https, and everything here is fetched by strangers.");
        }
    }
}
