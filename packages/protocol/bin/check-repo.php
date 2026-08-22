<?php

/**
 * Check this implementation against a real repository on the live network.
 *
 *   php bin/check-repo.php atproto.com
 *   php bin/check-repo.php did:plc:z72i7hdynmk6r22z27h6tvur
 *
 * Downloads somebody's repository, takes it apart, and rebuilds it from nothing
 * but the records inside — then compares every name it produced against the name
 * that repository's own server had already given it.
 *
 * Nothing here is a fixture. If this passes against an arbitrary stranger's
 * repository, the implementation agrees with a running network rather than with
 * its author, which is the only version of "correct" worth having for a format
 * two parties have to agree on.
 *
 * Read-only throughout. It fetches public data and writes nothing anywhere.
 */

require __DIR__.'/../vendor/autoload.php';

use StreetMesh\Protocol\Car;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\Commit;
use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\Handle;
use StreetMesh\Protocol\MerkleSearchTree;
use StreetMesh\Protocol\Multikey;
use StreetMesh\Protocol\PlcDirectory;

$subject = $argv[1] ?? 'atproto.com';
$network = new Curl(timeoutSeconds: 120, maximumBytes: 200 * 1024 * 1024);

$say = fn (string $line = '') => print $line."\n";
$failures = 0;

$check = function (string $what, bool $passed, string $detail = '') use ($say, &$failures): void {
    $failures += $passed ? 0 : 1;
    $say(sprintf('  %s %-46s %s', $passed ? '✓' : '✗', $what, $detail));
};

// ── Who ─────────────────────────────────────────────────────────────────────

$say();

$did = str_starts_with($subject, 'did:')
    ? $subject
    : (new Handle($network))->resolve($subject);

$say("  {$subject}");
$say("  {$did}");

$directory = new PlcDirectory($network);
$document = $directory->resolve($did);

$service = null;

foreach ($document['service'] ?? [] as $entry) {
    if (str_contains((string) ($entry['type'] ?? ''), 'PersonalDataServer')) {
        $service = $entry['serviceEndpoint'];
    }
}

if ($service === null) {
    exit("  That identity publishes no repository server.\n");
}

$say("  {$service}");
$say();

// ── The repository, as its own server hands it over ─────────────────────────

$car = $network->get($service.'/xrpc/com.atproto.sync.getRepo?did='.rawurlencode($did))
    ?? exit("  Its server would not hand over the repository.\n");

$archive = Car::read($car);
$commit = Commit::fromArray($archive->block($archive->roots[0]));

$say(sprintf('  %s bytes, %d blocks, revision %s', number_format(strlen($car)), $archive->count(), $commit->rev));
$say();

// ── Is every block the thing it claims to be? ────────────────────────────────

$intact = true;

foreach ($archive->cids() as $cid) {
    // block() re-hashes as it decodes, so this fails loudly on a substitution.
    $intact = $intact && (string) Cid::forBytes($archive->bytes($cid)) === $cid;
}

$check('every block hashes to the name it arrived under', $intact, $archive->count().' blocks');

// ── Is the commit signed by the identity it claims? ─────────────────────────

$signingKey = null;

foreach ($document['verificationMethod'] ?? [] as $method) {
    if (str_ends_with((string) ($method['id'] ?? ''), '#atproto')) {
        $signingKey = $method['publicKeyMultibase'];
    }
}

$check(
    'the commit is signed by the key that identity publishes',
    $signingKey !== null && $commit->verify($signingKey),
    $signingKey === null ? 'no signing key published' : Multikey::curveOf($signingKey),
);

$check('the commit names the identity we asked about', $commit->did === $did);

// ── Rebuild the tree from nothing but the records ───────────────────────────

$records = [];

$walk = function (Cid|string $cid) use ($archive, &$walk, &$records): void {
    $node = $archive->block($cid);

    if ($node['l'] !== null) {
        $walk($node['l']);
    }

    $previous = '';

    foreach ($node['e'] as $entry) {
        // Keys are stored as the part not shared with the key before them.
        $key = substr($previous, 0, $entry['p']).$entry['k']->value;
        $records[$key] = (string) $entry['v'];
        $previous = $key;

        if ($entry['t'] !== null) {
            $walk($entry['t']);
        }
    }
};

$walk($commit->data);

$built = (new MerkleSearchTree)->build($records);

$check('the tree rebuilds to the same root', (string) $built['root'] === (string) $commit->data, (string) $commit->data);

$missing = array_filter(array_keys($built['blocks']), fn (string $cid): bool => ! $archive->has($cid));

$check(
    'every node we built is in their archive, named the same',
    $missing === [],
    sprintf('%d of %d', count($built['blocks']) - count($missing), count($built['blocks'])),
);

// ── And are the records themselves what the tree says they are? ─────────────

$altered = [];

foreach ($records as $key => $cid) {
    if ($archive->has($cid) && (string) Cid::forBytes($archive->bytes($cid)) !== $cid) {
        $altered[] = $key;
    }
}

$check('every record matches the name the tree gives it', $altered === [], count($records).' records');

// ── ─────────────────────────────────────────────────────────────────────────

$say();
$say($failures === 0
    ? "  Agrees with the live network on every count.\n"
    : "  {$failures} disagreement(s) — this implementation is wrong, not the network.\n");

exit($failures === 0 ? 0 : 1);
