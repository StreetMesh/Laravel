<?php

namespace StreetMesh\Server\Protocol\Permissions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Identifiers already used, so a replay can be refused.
 *
 * Proofs and assertions are single-use, and neither the framework-free layer
 * nor either party can enforce that alone — it needs somewhere to remember what
 * has been seen, which is storage, which is here.
 *
 * The uniqueness is the database's rather than this code's. Checking and then
 * inserting leaves a gap between the two where a second request can pass the
 * check before the first has written, and two requests arriving together is
 * exactly the shape of a replay.
 */
final class Spent
{
    public function record(string $jti, int $expiresAt): void
    {
        try {
            DB::table('streetmesh_spent')->insert([
                'jti' => $jti,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ]);
        } catch (QueryException) {
            throw new RuntimeException('That has been used already.');
        }
    }

    /**
     * Rows are worthless once they expire — whatever they name can no longer be
     * accepted on its own terms.
     */
    public function sweep(): int
    {
        return DB::table('streetmesh_spent')->where('expires_at', '<', now())->delete();
    }
}
