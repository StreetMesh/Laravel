<?php

namespace StreetMesh\Protocol;

/**
 * The record tree everybody else's software already reads.
 *
 * A sorted tree whose shape is decided by the keys themselves rather than by the
 * order they arrived: hash a key, count the leading zero bits, and that is which
 * layer it belongs to. Two servers holding the same records therefore build
 * exactly the same tree, node for node, without coordinating — which is what
 * makes the root a name both of them can compute and neither can influence.
 *
 * The shape buys something a flat hash cannot: because a key's position is
 * derivable, a server can hand over one record and the handful of nodes between
 * it and the root, and the recipient can check it belongs without being given
 * everything else. That is what makes partial verification possible at all.
 *
 * Every rule here was read off a live repository rather than out of a
 * specification — the layering, the empty node that fills a gap, the byte-string
 * keys, and the prefix compression — and the tests check node names against the
 * ones a running network assigned.
 */
final class MerkleSearchTree implements RecordTree
{
    /**
     * Two bits per layer. A key with 0 or 1 leading zero bits sits at the
     * bottom, 2 or 3 one layer up, and so on — so each layer holds roughly a
     * quarter as many keys as the one below it.
     */
    private const BITS_PER_LAYER = 2;

    /**
     * @param  array<string, string|Cid>  $records  `collection/rkey` => record CID
     */
    public function root(array $records): Cid
    {
        return $this->build($records)['root'];
    }

    public function isInteroperable(): bool
    {
        return true;
    }

    /**
     * The tree, and every node in it.
     *
     * The nodes come back too because they are what an archive carries — a root
     * alone proves nothing to somebody who cannot rebuild the tree beneath it.
     *
     * @param  array<string, string|Cid>  $records
     * @return array{root: Cid, blocks: array<string, string>}
     */
    public function build(array $records): array
    {
        $entries = [];

        foreach ($records as $key => $value) {
            $entries[] = [
                'key' => (string) $key,
                'value' => $value instanceof Cid ? $value : Cid::parse($value),
            ];
        }

        usort($entries, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $blocks = [];

        if ($entries === []) {
            // An empty repository still has a root, or there would be nothing
            // for a first commit to sign.
            return ['root' => $this->store(['e' => [], 'l' => null], $blocks), 'blocks' => $blocks];
        }

        $layer = max(array_map(fn (array $entry): int => $this->layerOf($entry['key']), $entries));

        return ['root' => $this->node($entries, $layer, $blocks), 'blocks' => $blocks];
    }

    /**
     * Which layer a key belongs to, decided by the key and nothing else.
     */
    public function layerOf(string $key): int
    {
        $hash = hash('sha256', $key, binary: true);
        $zeros = 0;

        foreach (str_split($hash) as $byte) {
            $byte = ord($byte);

            for ($bit = 7; $bit >= 0; $bit--) {
                if (($byte >> $bit) & 1) {
                    return intdiv($zeros, self::BITS_PER_LAYER);
                }

                $zeros++;
            }
        }

        return intdiv($zeros, self::BITS_PER_LAYER);
    }

    /**
     * @param  array<int, array{key: string, value: Cid}>  $entries  sorted
     * @param  array<string, string>  $blocks
     */
    private function node(array $entries, int $layer, array &$blocks): Cid
    {
        $here = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $this->layerOf($entry['key']) === $layer,
        ));

        /*
         * No key belongs at this layer, but keys below it do. The layer is not
         * skipped: a node is written holding nothing but a link downward. Real
         * repositories contain these, which is not obvious from the rules and is
         * the sort of thing that makes a tree almost right.
         */
        if ($here === []) {
            return $this->store(['e' => [], 'l' => $this->node($entries, $layer - 1, $blocks)], $blocks);
        }

        $node = ['e' => [], 'l' => null];

        $before = $this->between($entries, null, $here[0]['key']);

        if ($before !== []) {
            $node['l'] = $this->node($before, $layer - 1, $blocks);
        }

        $previous = '';

        foreach ($here as $index => $entry) {
            $next = $here[$index + 1]['key'] ?? null;
            $under = $this->between($entries, $entry['key'], $next);

            /*
             * Keys are stored as the part not shared with the key before them.
             * Sorted keys in one node tend to share long prefixes — a whole
             * collection name, usually — so this is most of why a node stays
             * small, and getting the shared count wrong changes the node's name
             * without changing what it means.
             */
            $shared = $this->sharedPrefix($previous, $entry['key']);

            $node['e'][] = [
                'p' => $shared,
                'k' => Bytes::of(substr($entry['key'], $shared)),
                'v' => $entry['value'],
                't' => $under === [] ? null : $this->node($under, $layer - 1, $blocks),
            ];

            $previous = $entry['key'];
        }

        return $this->store($node, $blocks);
    }

    /**
     * The keys strictly between two boundaries, either of which may be open.
     *
     * @param  array<int, array{key: string, value: Cid}>  $entries
     * @return array<int, array{key: string, value: Cid}>
     */
    private function between(array $entries, ?string $after, ?string $before): array
    {
        return array_values(array_filter($entries, fn (array $entry): bool => ($after === null || strcmp($entry['key'], $after) > 0)
            && ($before === null || strcmp($entry['key'], $before) < 0)));
    }

    private function sharedPrefix(string $earlier, string $later): int
    {
        $shared = 0;
        $limit = min(strlen($earlier), strlen($later));

        while ($shared < $limit && $earlier[$shared] === $later[$shared]) {
            $shared++;
        }

        return $shared;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $blocks
     */
    private function store(array $node, array &$blocks): Cid
    {
        $bytes = DagCbor::encode($node);
        $cid = Cid::forBytes($bytes);

        $blocks[(string) $cid] = $bytes;

        return $cid;
    }
}
