<?php

namespace StreetMesh\Protocol;

/**
 * Reducing a set of records to a single name.
 *
 * A commit signs one value that stands for everything the signer is committing
 * to. How that value is derived decides two things: whether the commitment is
 * sound, and whether anybody else's software can check it.
 *
 * Both implementations here are sound. Only one of them is legible to the wider
 * network, which is why this is an interface rather than a function — the
 * substitution point is named so that swapping it is a change of one binding
 * rather than an excavation.
 */
interface RecordTree
{
    /**
     * The single name standing for every record given.
     *
     * @param  array<string, string>  $records  `collection/rkey` => record CID, any order
     */
    public function root(array $records): Cid;

    /**
     * Can other people's software check a commitment to this root?
     *
     * False means the root is sound but ours — it proves the signer committed to
     * exactly this set, and a stranger cannot recompute it with their own tools.
     */
    public function isInteroperable(): bool;
}
