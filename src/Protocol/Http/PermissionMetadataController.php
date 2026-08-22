<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Pkce;

/**
 * How a venue finds out where to ask, and what it will be asked for.
 *
 * The end of the chain a venue walks: a handle leads to a DID, a DID to this
 * server, this server to the first of these documents, and that to the second.
 * Every hop is somebody publishing something rather than two parties having
 * agreed anything, which is what lets a venue that has never heard of this
 * domicile do business with it.
 *
 * Both are read by servers with no account here and never any prospect of one.
 */
final class PermissionMetadataController
{
    /**
     * Who guards the records held here.
     *
     * Separate from the authorization server on purpose, even though for us
     * they are the same host: a domicile may keep records and let something
     * else do the granting, and a venue that assumed otherwise would be unable
     * to talk to it.
     */
    public function resource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/'),
            'authorization_servers' => [rtrim(url('/'), '/')],
            'scopes_supported' => [ClientMetadata::BASE_SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /**
     * What this server will and will not do.
     *
     * The two `true`s are the load-bearing ones. Requiring pushed requests is
     * what keeps the details out of the person's browser; reading a client
     * metadata document is what makes registration unnecessary. A venue checks
     * both before it will talk to us, and it is right to.
     */
    public function server(): JsonResponse
    {
        return response()->json([
            'issuer' => rtrim(url('/'), '/'),

            'pushed_authorization_request_endpoint' => route('streetmesh.oauth.par'),
            'authorization_endpoint' => route('streetmesh.oauth.authorize'),
            'token_endpoint' => route('streetmesh.oauth.token'),

            'require_pushed_authorization_requests' => true,
            'client_id_metadata_document_supported' => true,

            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'scopes_supported' => [ClientMetadata::BASE_SCOPE],

            /*
             * `plain` is absent deliberately. It is permitted by the base
             * specification, forbidden by this profile, and accepting it would
             * make the challenge worthless while looking like a courtesy.
             */
            'code_challenge_methods_supported' => [Pkce::METHOD],

            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'token_endpoint_auth_signing_alg_values_supported' => ['ES256'],
            'dpop_signing_alg_values_supported' => ['ES256'],

            'authorization_response_iss_parameter_supported' => true,
        ]);
    }
}
