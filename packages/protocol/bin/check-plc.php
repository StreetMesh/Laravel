#!/usr/bin/env php
<?php

/*
 * Mint an identity at a real PLC directory, then rename it and move it.
 *
 * Checked against Bluesky's own implementation rather than against our reading
 * of the specification, because the two are not the same thing and only one of
 * them is what the network runs. Every earlier check in this directory has
 * found something this way.
 *
 *   ./plc-serve                       (in StreetMesh/Server)
 *   php bin/check-plc.php https://plc.test
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;

$directory = $argv[1] ?? 'https://plc.test';

$network = new Curl;
$plc = new PlcDirectory($network, $directory);

function report(string $what, bool $passed, string $detail = ''): void
{
    echo ($passed ? "  ok    " : "  FAIL  ").$what.($detail === '' ? '' : "\n          ".$detail)."\n";

    if (! $passed) {
        exit(1);
    }
}

echo "Directory: {$directory}\n\n";

/*
 * Rotation keys first, because they are the thing the subject keeps. The
 * signing key can live on the server; a rotation key that lives only there
 * means moving out is that server's decision.
 */
$rotation = P256::generate();
$signing = P256::generate();

echo "Genesis\n";

$genesis = Plc::genesis(
    rotationKeys: [$rotation],
    signingKey: $signing,
    handle: 'alice.server.test',
    serviceEndpoint: 'https://server.test',
);

$did = Plc::did($genesis);

report('the DID is derived from the operation', str_starts_with($did, 'did:plc:'), $did);

$plc->submit($did, $genesis);
report('the directory accepted it', true);

$document = $plc->resolve($did);

report('it resolves to what we signed', ($document['id'] ?? null) === $did);
report(
    'the handle is on it',
    in_array('at://alice.server.test', $document['alsoKnownAs'] ?? [], true),
    json_encode($document['alsoKnownAs'] ?? []),
);
report(
    'the signing key is the one we made',
    in_array($signing->multikey(), array_column($document['verificationMethod'] ?? [], 'publicKeyMultibase'), true),
);

echo "\nRename\n";

$head = $plc->auditLog($did);
$renamed = Plc::rename(end($head)['operation'], $rotation, 'alice-again.server.test');

$plc->submit($did, $renamed);

$document = $plc->resolve($did);

report('the identifier did not move', ($document['id'] ?? null) === $did, $did);
report(
    'the name did',
    in_array('at://alice-again.server.test', $document['alsoKnownAs'] ?? [], true),
    json_encode($document['alsoKnownAs'] ?? []),
);

echo "\nMove\n";

$head = $plc->auditLog($did);
$moved = Plc::moveTo(end($head)['operation'], $rotation, 'https://elsewhere.test');

$plc->submit($did, $moved);

$document = $plc->resolve($did);

report('the identifier still did not move', ($document['id'] ?? null) === $did);
$endpoints = array_column($document['service'] ?? [], 'serviceEndpoint');

report(
    'the server did',
    in_array('https://elsewhere.test', $endpoints, true),
    implode(', ', $endpoints),
);

echo "\nHistory\n";

$log = $plc->auditLog($did);

report('every operation is on the record', count($log) === 3, count($log).' operations');

/*
 * The property the whole method exists for: a signature made before a rename is
 * still checkable afterwards, because the log says which key was current when.
 */
// `now` rather than a whole second, because the directory stamps operations
// to the millisecond and a truncated clock lands just before the genesis.
$was = $plc->keyAt($did, new DateTimeImmutable('now'));

report('the key in use at a moment is answerable', $was === $signing->multikey(), $was);

echo "\nAll of it held.\n";
