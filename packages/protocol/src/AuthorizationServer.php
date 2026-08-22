<?php

namespace StreetMesh\Protocol;

use JsonException;
use RuntimeException;

/**
 * Where to go to ask somebody's permission, found from nothing but their name.
 *
 * A venue is handed an address a stranger typed. To ask that stranger's server
 * for anything it must first find out which server that even is, and it must do
 * so without being configured, because the whole point is that it has never
 * heard of them.
 *
 * The chain is four hops and every one of them is somebody else's answer:
 *
 *   alice.example        a name a person can type
 *   → did:plc:…          who they are, permanently
 *   → their PDS          where their records live
 *   → protected resource which authorization server guards it
 *   → this               where to ask, and how
 *
 * Discovered rather than registered, and that is the substance of it. The
 * arrangement this replaces had a venue POST to a domicile to register itself
 * and get back a client identifier, which meant a row on both sides, a
 * handshake to keep in step, and a thing to go stale. None of that exists here:
 * a client identifier is a URL serving a document, and this is how the other
 * end is found. Two servers that have never met need to agree on nothing in
 * advance.
 *
 * @see https://atproto.com/specs/oauth
 */
final class AuthorizationServer
{
    /**
     * @param  array<int, string>  $dpopAlgorithms
     */
    private function __construct(
        public readonly string $issuer,
        public readonly string $pushedAuthorizationRequest,
        public readonly string $authorization,
        public readonly string $token,
        public readonly array $dpopAlgorithms,
    ) {}

    /**
     * The server that can grant permission over this account.
     *
     * `$document` resolves a DID to its document — the same shape `Handle`
     * takes, so a caller wires its resolver once and both use it.
     *
     * @param  callable(string): array<string, mixed>  $document
     */
    public static function forAccount(
        string $account,
        callable $document,
        Network $network = new Curl,
    ): self {
        $did = str_starts_with($account, 'did:')
            ? $account
            : (new Handle($network))->verify($account, $document);

        return self::atOrigin(
            self::guardianOf(self::personalDataServer($document($did), $did), $network),
            $network,
        );
    }

    /**
     * The metadata a server publishes about itself.
     */
    public static function atOrigin(string $origin, Network $network = new Curl): self
    {
        $origin = rtrim($origin, '/');

        $metadata = self::json(
            $network,
            $origin.'/.well-known/oauth-authorization-server',
            "[{$origin}] publishes no authorization server metadata.",
        );

        /*
         * The issuer must be the origin the document was fetched from. Without
         * that check a server could name somebody else as the issuer and have
         * tokens minted in their name — the one substitution this document
         * cannot otherwise be protected against, since it is fetched over
         * plain TLS with nothing signed inside it.
         */
        if (rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $origin) {
            throw new RuntimeException(
                "[{$origin}] publishes metadata issued by [".($metadata['issuer'] ?? 'nobody')."]."
            );
        }

        foreach (['pushed_authorization_request_endpoint', 'authorization_endpoint', 'token_endpoint'] as $required) {
            if (! is_string($metadata[$required] ?? null)) {
                throw new RuntimeException("[{$origin}] publishes no {$required}.");
            }
        }

        /*
         * Both are mandatory in this profile, and a server without them cannot
         * be talked to at all — better to say so here than to fail later inside
         * a request whose shape it was never going to accept.
         */
        if (($metadata['require_pushed_authorization_requests'] ?? false) !== true) {
            throw new RuntimeException("[{$origin}] does not require pushed authorization requests.");
        }

        if (($metadata['client_id_metadata_document_supported'] ?? false) !== true) {
            throw new RuntimeException("[{$origin}] will not read a client metadata document.");
        }

        return new self(
            issuer: $origin,
            pushedAuthorizationRequest: $metadata['pushed_authorization_request_endpoint'],
            authorization: $metadata['authorization_endpoint'],
            token: $metadata['token_endpoint'],
            dpopAlgorithms: array_values(array_filter(
                (array) ($metadata['dpop_signing_alg_values_supported'] ?? []),
                is_string(...),
            )),
        );
    }

    /**
     * Can this server be talked to with a signature we know how to make?
     *
     * Asked separately because "we cannot sign for this server" and "this
     * server refused us" are different answers, and a client that cannot tell
     * them apart reports a missing capability as a rejection.
     */
    public function accepts(string $algorithm): bool
    {
        return in_array($algorithm, $this->dpopAlgorithms, strict: true);
    }

    /**
     * Which authorization server guards a resource, according to the resource.
     *
     * Asked of the resource rather than assumed to be the resource, because the
     * two are separable — a host may keep records and delegate the granting of
     * permission over them to a server run by somebody else entirely.
     */
    private static function guardianOf(string $resource, Network $network): string
    {
        $metadata = self::json(
            $network,
            rtrim($resource, '/').'/.well-known/oauth-protected-resource',
            "[{$resource}] does not say who guards it.",
        );

        $servers = array_values(array_filter(
            (array) ($metadata['authorization_servers'] ?? []),
            is_string(...),
        ));

        return $servers[0] ?? throw new RuntimeException("[{$resource}] names no authorization server.");
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function personalDataServer(array $document, string $did): string
    {
        foreach ((array) ($document['service'] ?? []) as $service) {
            if (str_contains((string) ($service['type'] ?? ''), 'PersonalDataServer')) {
                return (string) $service['serviceEndpoint'];
            }
        }

        throw new RuntimeException("[{$did}] publishes no repository server.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Network $network, string $url, string $absent): array
    {
        $body = $network->get($url) ?? throw new RuntimeException($absent);

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("[{$url}] did not answer with JSON.");
        }

        return is_array($decoded) ? $decoded : throw new RuntimeException("[{$url}] did not answer with an object.");
    }
}
