<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\FlatTree;
use StreetMesh\Protocol\MerkleSearchTree;

class MerkleSearchTreeTest extends TestCase
{
    private const VECTORS = __DIR__.'/../conformance/encoding/merkle-search-tree.json';

    /**
     * @return array<string, mixed>
     */
    private function suite(): array
    {
        if (! is_file(self::VECTORS)) {
            $this->markTestSkipped('Conformance vectors are absent. Run `composer conformance`.');
        }

        return json_decode((string) file_get_contents(self::VECTORS), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The rule that decides the whole shape, checkable on its own.
     */
    public function test_a_keys_layer_follows_from_the_key(): void
    {
        $tree = new MerkleSearchTree;

        foreach ($this->suite()['layers']['vectors'] as $vector) {
            $this->assertSame($vector['layer'], $tree->layerOf($vector['key']), "layer of [{$vector['key']}]");
        }
    }

    public function test_every_tree_vector_reproduces(): void
    {
        $tree = new MerkleSearchTree;

        foreach ($this->suite()['trees'] as $vector) {
            $built = $tree->build($vector['records']);

            $this->assertSame($vector['root'], (string) $built['root'], "root for [{$vector['name']}]");
            $this->assertCount($vector['nodeCount'], $built['blocks'], "node count for [{$vector['name']}]");
        }
    }

    /**
     * The property the whole design rests on: the tree follows from the records,
     * not from the order they arrived. Two servers holding the same records must
     * compute the same name for them without ever speaking.
     */
    public function test_the_tree_does_not_depend_on_insertion_order(): void
    {
        $records = $this->suite()['trees'][1]['records'];

        $shuffled = $records;
        uksort($shuffled, fn (string $a, string $b): int => strcmp(strrev($a), strrev($b)));

        $tree = new MerkleSearchTree;

        $this->assertSame(
            (string) $tree->build($records)['root'],
            (string) $tree->build($shuffled)['root'],
        );
    }

    public function test_changing_anything_at_all_changes_the_root(): void
    {
        $records = $this->suite()['trees'][1]['records'];
        $tree = new MerkleSearchTree;
        $root = (string) $tree->build($records)['root'];

        $added = [...$records, 'com.streetmesh.games.chess/3mqcp5qjdfs26' => (string) Cid::forBytes('a game')];
        $this->assertNotSame($root, (string) $tree->build($added)['root']);

        $removed = $records;
        array_shift($removed);
        $this->assertNotSame($root, (string) $tree->build($removed)['root']);

        $altered = $records;
        $altered[array_key_first($altered)] = (string) Cid::forBytes('something else');
        $this->assertNotSame($root, (string) $tree->build($altered)['root']);
    }

    /**
     * Every node has to be produced, not just the root — an archive carries
     * them, and a root alone proves nothing to somebody who cannot rebuild what
     * is underneath it.
     */
    public function test_the_nodes_beneath_the_root_come_back_too(): void
    {
        $built = (new MerkleSearchTree)->build($this->suite()['trees'][1]['records']);

        $this->assertArrayHasKey((string) $built['root'], $built['blocks']);

        foreach ($built['blocks'] as $cid => $bytes) {
            // Each node's name must be the hash of the node, or an archive built
            // from these would be internally inconsistent.
            $this->assertSame($cid, (string) Cid::forBytes($bytes));
        }
    }

    public function test_it_says_it_is_interoperable_and_the_flat_one_does_not(): void
    {
        $this->assertTrue((new MerkleSearchTree)->isInteroperable());
        $this->assertFalse((new FlatTree)->isInteroperable());
    }
}
