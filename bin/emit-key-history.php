<?php

/**
 * Write the key-history vector from a real identity that has actually rotated.
 *
 * A synthetic log would only pin what its author already believed. This embeds
 * genuine operations from the public directory, alongside the answers the
 * network's own history gives, so an implementation that reproduces it has
 * agreed with a running system.
 *
 *   php bin/emit-key-history.php ../Protocol/conformance
 *
 * Vector generation is being consolidated here from the prototype that produced
 * the earlier files; this is the first of them to live with the implementation
 * it describes.
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;

$destination = rtrim($argv[1] ?? '../Protocol/conformance', '/');

$directory = new PlcDirectory;

$vectors = [];

// Chosen because it has a genuine rotation in its history: four operations, of
// which only one changes the signing key. An implementation that reports four
// periods instead of two is counting operations rather than rotations.
foreach (['did:plc:z72i7hdynmk6r22z27h6tvur'] as $did) {
    $log = $directory->auditLog($did);
    $history = Plc::keyHistory($log);

    if (count($history) < 2) {
        fwrite(STDERR, "  {$did} has not rotated; it makes a poor vector.\n");

        continue;
    }

    $before = $history[0];
    $after = $history[1];

    $vectors[] = [
        'name' => 'an identity that rotated its signing key',
        'source' => 'https://plc.directory/'.$did.'/log/audit',
        'did' => $did,
        'auditLog' => $log,
        'fragment' => 'atproto',
        'history' => $history,
        'queries' => [
            [
                'name' => 'the moment the identity was created',
                'at' => $before['from'],
                'key' => $before['key'],
            ],
            [
                'name' => 'midway through the first key',
                'at' => '2023-06-01T00:00:00.000Z',
                'key' => $before['key'],
            ],
            [
                'name' => 'the instant of the rotation belongs to the new key',
                'at' => $after['from'],
                'key' => $after['key'],
            ],
            [
                'name' => 'now, which is the only answer a DID document gives',
                'at' => '2099-01-01T00:00:00.000Z',
                'key' => $after['key'],
            ],
            [
                'name' => 'before the identity existed',
                'at' => '2020-01-01T00:00:00.000Z',
                'key' => null,
            ],
        ],
    ];
}

$document = [
    '$comment' => 'Which key an identity was using at a given moment, from its audit log. '
        .'`history` is the sequence of keys with the instant each became current and the instant it stopped; '
        .'a period is bounded by [from, until). Operations that do not change the key do not start a new period, '
        .'and nullified operations are ignored entirely. '
        .'A query at a time before the identity existed has no answer and must be refused rather than guessed.',
    'vectors' => $vectors,
];

$file = $destination.'/identity/key-history.json';

if (! is_dir(dirname($file))) {
    mkdir(dirname($file), recursive: true);
}

file_put_contents(
    $file,
    json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

printf("wrote %s — %d vectors\n", $file, count($vectors));
