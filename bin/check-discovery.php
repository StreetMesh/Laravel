<?php

/**
 * Check the discovery chain against the live network.
 *
 *   php bin/check-discovery.php bsky.app
 *   php bin/check-discovery.php did:plc:z72i7hdynmk6r22z27h6tvur
 *
 * Takes a name a person could type and walks it all the way to the server that
 * can grant permission over that account, using nothing but what each hop
 * publishes. Nothing is configured and nothing is a fixture.
 *
 * The value of running it against a stranger is that the whole chain is other
 * people's data: their DNS, their DID document, their PDS, their authorization
 * server. If it works here it works because we agree with a running network
 * rather than with our reading of a specification.
 *
 * Read-only throughout. It fetches public documents and writes nothing anywhere.
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\AuthorizationServer;
use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Handle;
use StreetMesh\Protocol\PlcDirectory;

$subject = $argv[1] ?? 'bsky.app';
$network = new Curl(timeoutSeconds: 30);
$directory = new PlcDirectory($network);

$say = fn (string $line = '') => print $line."\n";
$failures = 0;

$check = function (string $what, bool $passed, string $detail = '') use ($say, &$failures): void {
    $failures += $passed ? 0 : 1;
    $say(sprintf('  %s %-44s %s', $passed ? '✓' : '✗', $what, $detail));
};

/*
 * Both methods, because an account is free to use either and a client that
 * handles only one can talk to only half the network.
 */
$document = fn (string $did): array => str_starts_with($did, 'did:web:')
    ? json_decode((string) $network->get(Did::parse($did)->documentUrl()), true)
    : $directory->resolve($did);

$say();
$say("  {$subject}");

try {
    $did = str_starts_with($subject, 'did:') ? $subject : (new Handle($network))->verify($subject, $document);

    $check('the name resolves, and answers to itself', true, $did);

    $server = AuthorizationServer::forAccount($subject, $document, $network);

    $check('the chain reaches an authorization server', true, $server->issuer);
    $check('it requires pushed authorization requests', true, 'checked while parsing');
    $check('it will read a client metadata document', true, 'checked while parsing');
    $check(
        'it accepts a signature we can make',
        $server->accepts('ES256'),
        'ES256 '.($server->accepts('ES256') ? 'offered' : 'NOT offered').' — '.implode(', ', $server->dpopAlgorithms),
    );

    $say();
    $say('  ask here    '.$server->pushedAuthorizationRequest);
    $say('  send them   '.$server->authorization);
    $say('  redeem at   '.$server->token);
} catch (Throwable $failure) {
    $check('the chain completes', false, $failure->getMessage());
}

$say();
$say($failures === 0
    ? "  Agrees with the live network on every count.\n"
    : "  {$failures} disagreement(s) — this implementation is wrong, not the network.\n");

exit($failures === 0 ? 0 : 1);
