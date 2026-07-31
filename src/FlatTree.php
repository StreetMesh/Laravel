<?php

namespace StreetMesh\Protocol;

/**
 * Every record in one sorted list, hashed.
 *
 * Sound and not interoperable, and it is worth being plain about which half is
 * which rather than letting the shortfall hide.
 *
 * **Sound:** the root covers every record's address and content, in an order
 * that does not depend on the order they were written. Adding, removing or
 * altering any record changes the root, so a signature over the root is a
 * commitment to exactly that set and no other.
 *
 * **Not interoperable:** ATProtocol derives its root from a Merkle Search Tree,
 * whose shape lets a server prove one record belongs without handing over the
 * rest. This cannot do that — checking anything means holding everything — and a
 * root computed here is not a root their software recognises.
 *
 * So a chain built on this is honest history that only we can read. It is the
 * right thing to run now and the wrong thing to still be running when
 * interoperability matters, and swapping it changes one binding.
 */
final class FlatTree implements RecordTree
{
    /**
     * @param  array<string, string>  $records
     */
    public function root(array $records): Cid
    {
        // Sorted so that the root depends on the set rather than on the order it
        // happened to arrive in — two servers holding the same records must
        // arrive at the same name for them.
        ksort($records, SORT_STRING);

        return Cid::forRecord([
            'type' => 'streetmesh.flatTree',
            'entries' => array_map(
                fn (string $key, string $cid): array => ['k' => $key, 'v' => $cid],
                array_keys($records),
                array_values($records),
            ),
        ]);
    }

    public function isInteroperable(): bool
    {
        return false;
    }
}
