<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Identity\Identities;

/**
 * The two documents a venue publishes so a stranger's server can trust it.
 *
 * Between them they are the whole of client registration. A domicile that has
 * never heard of this venue reads the first to learn what it is, and the second
 * to check what it signed — and nothing is exchanged, agreed or stored on either
 * side beforehand.
 *
 * Both are public and both must be, which is worth stating plainly next to
 * anything that publishes a key: what goes out here is the half that verifies,
 * never the half that signs.
 */
final class ClientController
{
    public function metadata(Identities $identities): JsonResponse
    {
        $server = $identities->forServer();
        $host = 'https://'.$server->handle;

        return response()->json(ClientMetadata::forVenue(
            clientId: route('streetmesh.client'),
            clientName: (string) config('app.name'),
            clientUri: $host,
            redirectUris: [$this->redirect()],
            jwksUri: route('streetmesh.jwks'),
            scopes: (array) config('streetmesh.oauth.scopes', []),
        )->toArray());
    }

    /**
     * Where a domicile sends somebody back to.
     *
     * Looked up by route name rather than written down, because this address is
     * published here *and* sent with every authorization request, and a domicile
     * refuses the request if the two disagree. Kept as two strings, moving the
     * route would have broken every authorization silently — and the refusal
     * arrives from somebody else's server, where it reads as their fault.
     *
     * Resolved now rather than at boot, so that it does not depend on which
     * provider registered its routes first.
     *
     * An operator who sets an absolute URL still wins; `??` rather than
     * config()'s second argument, which is returned only when the key is
     * absent, and the key is present and null whenever nothing has set it.
     */
    private function redirect(): string
    {
        return (string) (config('streetmesh.oauth.redirect')
            ?? route((string) config('streetmesh.oauth.redirect_route')));
    }

    /**
     * The public halves of what this venue signs with.
     *
     * Named by the fragment the identity already uses for its signing key, so
     * the same key has one name here and in the DID document rather than two.
     */
    public function keys(Identities $identities): JsonResponse
    {
        $key = $identities->forServer()->key();

        if (! $key instanceof P256) {
            throw new RuntimeException(
                'This server signs on '.$key->curve().', which JOSE has no agreed spelling for here. '
                .'A client key must be P-256.'
            );
        }

        return response()->json(ClientMetadata::keySet(['atproto' => Jwk::forP256($key)]));
    }
}
