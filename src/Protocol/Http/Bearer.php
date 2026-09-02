<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\Request;
use RuntimeException;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Server\Protocol\Permissions\Permission;
use StreetMesh\Server\Protocol\Permissions\Permissions;

/**
 * Who is presenting a permission, and whether the key presenting it is theirs.
 *
 * One place rather than one per endpoint. Every request a venue makes on
 * somebody's behalf is admitted the same way -- a token, and a proof that the
 * holder still has the key it was issued to -- and two endpoints agreeing about
 * that by coincidence is how one of them ends up admitting a stolen token after
 * the other has been fixed.
 *
 * Nothing here decides what may be done. That is the scope's job, at the
 * endpoint, where what is being attempted is known.
 */
final readonly class Bearer
{
    public function __construct(private Permissions $permissions) {}

    /**
     * The permission behind this request, or a refusal explaining itself.
     *
     * Throws rather than returning null: every caller answers 401 and there is
     * nothing else to do with a request that arrived without a usable token.
     */
    public function in(Request $request): Permission
    {
        $header = (string) $request->header('Authorization');

        if (! str_starts_with($header, 'DPoP ')) {
            throw new RuntimeException('A token here is presented with a proof, not on its own.');
        }

        $token = substr($header, strlen('DPoP '));

        /*
         * The proof is over this method and this address, so a proof lifted
         * from one request cannot admit another.
         */
        $thumbprint = Dpop::check(
            (string) $request->header('DPoP'),
            $request->method(),
            $request->url(),
            accessToken: $token,
        );

        return $this->permissions->holder($token, $thumbprint);
    }
}
