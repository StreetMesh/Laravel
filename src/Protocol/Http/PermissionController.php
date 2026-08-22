<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use Throwable;

/**
 * The two endpoints a venue talks to, and neither is for a browser.
 *
 * The screen a person actually sees lives in whatever interface package is
 * installed — a domicile's consent screen is its own to design, and this
 * package has no opinion about what it looks like. What is here is the part two
 * servers must agree on exactly.
 */
final class PermissionController
{
    public function __construct(private readonly Permissions $permissions) {}

    /**
     * The venue pushes what it wants, and gets a handle back.
     */
    public function push(Request $request): JsonResponse
    {
        return $this->guarded($request, function (string $thumbprint) use ($request): JsonResponse {
            $permission = $this->permissions->push($request->all(), $this->issuer(), $thumbprint);

            return response()->json([
                'request_uri' => $permission->request_uri,
                'expires_in' => Permissions::REQUEST_SECONDS,
            ], 201);
        });
    }

    /**
     * The code, or the refresh token, traded for a live one.
     */
    public function token(Request $request): JsonResponse
    {
        return $this->guarded($request, function (string $thumbprint) use ($request): JsonResponse {
            $granted = $request->input('grant_type') === 'refresh_token'
                ? $this->permissions->refresh($request->all(), $this->issuer(), $thumbprint)
                : $this->permissions->redeem($request->all(), $this->issuer(), $thumbprint);

            return response()->json([
                'access_token' => $granted['access'],

                /*
                 * Named for what it is. A client that treated this as a bearer
                 * token would send it without a proof and be refused, which is
                 * the correct outcome but a confusing one to debug.
                 */
                'token_type' => 'DPoP',

                'refresh_token' => $granted['refresh'],
                'expires_in' => Permissions::TOKEN_SECONDS,
                'scope' => $granted['permission']->scope,
                'sub' => $granted['permission']->did,
            ]);
        });
    }

    /**
     * Every request here carries a proof, and every one may be told to try
     * again with a nonce.
     *
     * The nonce is mandatory in this profile and rotates, so being asked for a
     * new one is an ordinary event in a working conversation rather than a
     * failure. A client that treats it as an error works until the first
     * rotation and then stops.
     *
     * @param  callable(string): JsonResponse  $then
     */
    private function guarded(Request $request, callable $then): JsonResponse
    {
        $proof = $request->header('DPoP');

        if (! is_string($proof) || $proof === '') {
            return $this->challenge('A request here has to prove possession of a key.');
        }

        $nonce = $this->nonce();

        try {
            $thumbprint = Dpop::check($proof, $request->method(), $request->url(), nonce: $nonce);
        } catch (Throwable $refused) {
            return $this->challenge($refused->getMessage());
        }

        try {
            return $then($thumbprint)->header('DPoP-Nonce', $nonce);
        } catch (Throwable $refused) {
            /*
             * Deliberately one shape of answer for every refusal. Which check
             * failed is useful to us and useful to somebody guessing, and the
             * venue's own logs will say what it sent.
             */
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => $refused->getMessage(),
            ], 400)->header('DPoP-Nonce', $nonce);
        }
    }

    private function challenge(string $why): JsonResponse
    {
        return response()->json([
            'error' => 'use_dpop_nonce',
            'error_description' => $why,
        ], 400)->header('DPoP-Nonce', $this->nonce());
    }

    /**
     * One nonce for everybody, rotated on a fixed interval.
     *
     * Per-client nonces would mean state per stranger, which is exactly what
     * this design avoids everywhere else. The specification caps the lifetime
     * at five minutes and this is well inside it.
     */
    private function nonce(): string
    {
        return Cache::remember(
            'streetmesh:dpop-nonce:'.intdiv(time(), 120),
            180,
            fn (): string => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
        );
    }

    private function issuer(): string
    {
        return rtrim(url('/'), '/');
    }
}
