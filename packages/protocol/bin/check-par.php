<?php

/**
 * Check that a real authorization server accepts the request a venue composes.
 *
 *   php bin/check-par.php bnewbold.net
 *
 * Discovers where to ask permission for an account and pushes a complete,
 * properly signed authorization request at it — PKCE challenge, client
 * assertion, DPoP proof, the lot.
 *
 * What it can establish, and what it cannot, is the point of the script — and
 * the answer is narrower than it first appears.
 *
 * Real servers validate in this order, which this script was used to find out:
 *
 *   1. the DPoP proof          → use_dpop_nonce, then accepted
 *   2. the client_id's shape   → invalid_client_id for a local hostname
 *   3. fetching the client     → invalid_client_metadata for a 404
 *   4. everything else
 *
 * So a venue with no publicly reachable metadata document never gets to step 4.
 * Reaching step 2 or 3 proves the DPoP proof was accepted and the body parsed —
 * genuinely worth knowing, and not nothing — but it says nothing whatsoever
 * about the client assertion or the PKCE challenge, which are checked later.
 *
 * Those two are covered by unit tests and by our own authorization server, and
 * will only be checked against a stranger's once a venue is deployed somewhere
 * with a public address.
 *
 * One request to one server, made with a client identifier that cannot resolve,
 * so nothing is created anywhere.
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\AuthorizationRequest;
use StreetMesh\Protocol\AuthorizationServer;
use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;
use StreetMesh\Protocol\PlcDirectory;

$subject = $argv[1] ?? 'bnewbold.net';
$client = $argv[2] ?? 'https://protocol.streetmesh.com/client-metadata.json';

$network = new Curl(timeoutSeconds: 30);
$directory = new PlcDirectory($network);
$say = fn (string $line = '') => print $line."\n";

$document = fn (string $did): array => str_starts_with($did, 'did:web:')
    ? json_decode((string) $network->get(Did::parse($did)->documentUrl()), true)
    : $directory->resolve($did);

/**
 * @param  array<string, string>  $form
 * @return array{status: int, headers: array<string, string>, body: string}
 */
$post = function (string $url, array $form, string $proof): array {
    $handle = curl_init($url);
    $headers = [];

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($form),
        CURLOPT_HTTPHEADER => ['DPoP: '.$proof, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($ignored, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    curl_close($handle);

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
};

$say();
$say("  {$subject}");

$server = AuthorizationServer::forAccount($subject, $document, $network);

$say('  '.$server->pushedAuthorizationRequest);
$say();

$key = P256::generate();
$pkce = Pkce::generate();

$fields = AuthorizationRequest::pushed(
    clientId: $client,
    redirectUri: rtrim(dirname($client), '/').'/connect/callback',
    state: bin2hex(random_bytes(8)),
    pkce: $pkce,
    assertion: ClientAssertion::for($client, $server->issuer, $key),
    loginHint: str_starts_with($subject, 'did:') ? null : $subject,
);

$say('  sending    '.implode(', ', array_keys($fields)));
$say();

// The nonce is asked for once and then echoed, which is the ordinary path.
$first = $post($server->pushedAuthorizationRequest, $fields, Dpop::proof($key, 'POST', $server->pushedAuthorizationRequest));
$nonce = Dpop::nonceFrom($first['headers']);

$say('  first ask  HTTP '.$first['status'].'   '.(json_decode($first['body'], true)['error'] ?? '(none)'));

if ($nonce === null) {
    $say("\n  That server issued no nonce, so this proves nothing either way.\n");

    exit(1);
}

$second = $post(
    $server->pushedAuthorizationRequest,
    $fields,
    Dpop::proof($key, 'POST', $server->pushedAuthorizationRequest, nonce: $nonce),
);

$answer = json_decode($second['body'], true);
$error = $answer['error'] ?? '(none)';

$say('  second ask HTTP '.$second['status'].'   '.$error);
$say('             '.($answer['error_description'] ?? ''));
$say();

/*
 * Both of these mean the proof was accepted and the body parsed, and that the
 * server then failed on a client it cannot reach — which is as far as a venue
 * with a local address can get. Neither says anything about the assertion.
 */
$reachedTheClient = in_array($error, ['invalid_client_id', 'invalid_client_metadata', '(none)'], strict: true);

$say($reachedTheClient
    ? "  ✓ Proof accepted and request parsed. It stopped at fetching our client,\n"
        ."    which is the furthest a venue without a public address can go — the\n"
        ."    assertion and the PKCE challenge are checked after this point.\n"
    : "  ✗ It rejected the request itself: {$error}\n");

exit($reachedTheClient ? 0 : 1);
