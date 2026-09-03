<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A wardrobe rather than a face.
 *
 * The records were always a collection: every choice writes a new one and the
 * old ones stand, which is how somebody can see what they used to look like.
 * Only the projection collapsed them, because `unique('did')` allowed exactly
 * one row per resident and each new face overwrote the last.
 *
 * The table said so at the time — "the line to change when a resident may keep
 * several, at which point the pair becomes (did, rkey) and `is_default` starts
 * earning its keep" — and this is that line. `is_default` has been sitting
 * there meaning the right thing and never having to prove it.
 *
 * Nothing is lost going forward and nothing is recovered going back: rows
 * overwritten while the old constraint stood are gone from the index, though
 * the records they were projected from are not. Replaying those is a separate
 * job from this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->dropUnique(['did']);

            /*
             * Still unique, on the pair. A resident may keep several avatars
             * and may not keep the same one twice: an rkey names one record,
             * and one record is one avatar however many times it is projected.
             */
            $table->unique(['did', 'rkey']);
        });
    }

    /**
     * Back to one, which means choosing which one.
     *
     * A stricter constraint cannot be restored over data that already uses the
     * looser one, so this keeps the avatar each resident is wearing and drops
     * the rest of the index. It is lossy and there is no version of it that is
     * not: a column that holds one row per resident cannot hold four.
     *
     * The records are untouched, as ever. What is discarded here is the
     * projection, and a projection is rebuildable by replaying them.
     */
    public function down(): void
    {
        DB::table('streetmesh_avatars')->where('is_default', false)->delete();

        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->dropUnique(['did', 'rkey']);
            $table->unique('did');
        });
    }
};
