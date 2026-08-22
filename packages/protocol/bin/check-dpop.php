<?php

/**
 * Check that a real authorization server accepts a proof we construct.
 *
 *   php bin/check-dpop.php bnewbold.net
 *
 * Discovers where to ask permission for an account, then makes one pushed
 * authorization request carrying a DPoP proof, and reports what came back.
 *
 * What this can and cannot establish is worth being exact about. It cannot get
 * a token: that needs a client metadata document published at a public HTTPS
 * URL and a person at a browser approving something. What it *can* establish is
 * that our proof parses — a server that rejects a malformed proof says so, and
 * a server that reads ours will instead answer the nonce challenge that this
 * profile makes mandatory. That is the half of DPoP with no unit test, because
 * only the other end can tell us we got it right.
 *
 * One request to one server. A pushed authorization request is an ordinary
 * unauthenticated protocol call and this one is made with a client identifier
 * that cannot succeed, so nothing is created anywhere.
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\AuthorizationServer;
use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Dpop;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\PlcDirectory;

$subject = $argv[1] ?? 'bnewbold.net';
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

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($form),
        CURLOPT_HTTPHEADER => ['DPoP: '.$proof, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $headers = [];

    curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ignored, string $line) use (&$headers): int {
        $parts = explode(':', $line, 2);

        if (count($parts) === 2) {
            $headers[trim($parts[0])] = trim($parts[1]);
        }

        return strlen($line);
    });

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

$say('  our key    '.Jwk::forP256($key)->thumbprint());

/*
 * A client identifier that resolves to nothing, on purpose. We are asking
 * whether the proof is read, not trying to be authorized.
 */
$form = [
    'client_id' => 'https://streetmesh.invalid/client-metadata.json',
    'response_type' => 'code',
    'redirect_uri' => 'https://streetmesh.invalid/callback',
    'scope' => 'atproto',
    'state' => bin2hex(random_bytes(8)),
    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'a-verifier', binary: true)), '+/', '-_'), '='),
    'code_challenge_method' => 'S256',
];

$attempt = $post(
    $server->pushedAuthorizationRequest,
    $form,
    Dpop::proof($key, 'POST', $server->pushedAuthorizationRequest),
);

$nonce = Dpop::nonceFrom($attempt['headers']);
$error = json_decode($attempt['body'], true)['error'] ?? '(none)';

$say();
$say('  first ask  HTTP '.$attempt['status'].'   error: '.$error);
$say('  nonce      '.($nonce ?? 'none offered'));

if ($nonce === null) {
    $say();
    $say("  That server issued no nonce, so this proves nothing either way.\n");

    exit(1);
}

/*
 * The nonce dance. Being told to use a new one is an ordinary event rather than
 * a failure, and a client that treats it as an error works until the server
 * next rotates and then stops.
 */
$second = $post(
    $server->pushedAuthorizationRequest,
    $form,
    Dpop::proof($key, 'POST', $server->pushedAuthorizationRequest, nonce: $nonce),
);

$secondError = json_decode($second['body'], true)['error'] ?? '(none)';

$say('  second ask HTTP '.$second['status'].'   error: '.$secondError);
$say();

/*
 * `use_dpop_nonce` means "your proof was fine, take this nonce". Anything about
 * the proof itself — invalid_dpop_proof — means it was not.
 */
$readOurProof = $secondError !== 'invalid_dpop_proof' && $secondError !== 'use_dpop_nonce';

$say($readOurProof
    ? "  ✓ It read our proof and moved on to the client, which is as far as this can go.\n"
    : "  ✗ It would not accept the proof: {$secondError}\n");

exit($readOurProof ? 0 : 1);
