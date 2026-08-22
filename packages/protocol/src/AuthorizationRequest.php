<?php

namespace StreetMesh\Protocol;

use SensitiveParameter;

/**
 * Asking somebody's server for permission, and redeeming the answer.
 *
 * Two messages, and the order is the whole shape of this profile. The venue
 * pushes what it wants straight to the authorization server and gets back a
 * short handle; only then does it send the person to their own server, carrying
 * nothing but that handle. Their browser never sees the request, so nothing in
 * it can be read or rewritten in passing — which is why PAR is required here
 * rather than offered.
 *
 * Building the messages is all this does. Sending them, and remembering what
 * was asked between the two halves, belongs to whatever is hosting the venue.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9126
 */
final class AuthorizationRequest
{
    /**
     * What the venue pushes, before anybody is redirected anywhere.
     *
     * `loginHint` is the address the person typed. Passing it saves them typing
     * it a second time on their own server, and it is a hint in the literal
     * sense — their server decides who is signing in, not us.
     *
     * @param  array<int, string>  $scopes
     * @return array<string, string>
     */
    public static function pushed(
        string $clientId,
        string $redirectUri,
        string $state,
        Pkce $pkce,
        string $assertion,
        array $scopes = [ClientMetadata::BASE_SCOPE],
        ?string $loginHint = null,
    ): array {
        $fields = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scopes),
            'code_challenge' => $pkce->challenge(),
            'code_challenge_method' => Pkce::METHOD,
            'client_assertion_type' => ClientAssertion::TYPE,
            'client_assertion' => $assertion,
        ];

        if ($loginHint !== null) {
            $fields['login_hint'] = $loginHint;
        }

        return $fields;
    }

    /**
     * Where to send the person, once the request has been accepted.
     *
     * Two parameters and no more. Everything else is already held by the server
     * they are about to arrive at, under the handle it gave us.
     */
    public static function redirectTo(string $authorizationEndpoint, string $requestUri, string $clientId): string
    {
        return $authorizationEndpoint.'?'.http_build_query([
            'client_id' => $clientId,
            'request_uri' => $requestUri,
        ]);
    }

    /**
     * Trading the code for a token.
     *
     * The verifier appears here for the first time, which is the point of it:
     * whatever carried the code back cannot also produce this.
     *
     * @return array<string, string>
     */
    public static function redeem(
        string $clientId,
        string $redirectUri,
        string $code,
        #[SensitiveParameter]
        Pkce $pkce,
        string $assertion,
    ): array {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $pkce->verifier,
            'client_assertion_type' => ClientAssertion::TYPE,
            'client_assertion' => $assertion,
        ];
    }

    /**
     * Trading a refresh token for a fresh one.
     *
     * Access tokens here are good for well under an hour, so this is the
     * ordinary path rather than an edge case, and the refresh token is normally
     * single-use — the response carries its replacement.
     *
     * @return array<string, string>
     */
    public static function refresh(
        string $clientId,
        #[SensitiveParameter]
        string $refreshToken,
        string $assertion,
    ): array {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_assertion_type' => ClientAssertion::TYPE,
            'client_assertion' => $assertion,
        ];
    }
}
