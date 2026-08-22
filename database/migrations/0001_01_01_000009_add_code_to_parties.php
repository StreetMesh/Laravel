<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A word you can say out loud to let somebody into a party.
 *
 * This weakens something that was deliberate, and it is worth writing down
 * rather than discovering later. A party was invite-only in the strict sense:
 * the only way in was for somebody already inside to point at a name they could
 * see in the room they were both in, which meant an invitation was an act
 * between two people who were both present. A code is not that. It can be
 * pasted into a message, forwarded, read over a shoulder — whoever holds it can
 * join, and the party cannot tell how they came by it.
 *
 * It is here because being able to say "join with 4F2K" to somebody sitting
 * next to you is worth more in practice than the property it costs, and because
 * the blast radius is small: a party is at most four people at one venue, and
 * anybody in it can see who turned up.
 *
 * Kept narrow deliberately — it dies with the party, it can be rotated by
 * anybody inside, and it will not admit anybody once the party is full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_parties', function (Blueprint $table): void {
            /*
             * Short and unambiguous rather than random-looking. This is a thing
             * people read aloud across a table, so it avoids the characters
             * that sound or look like each other — see `Parties::freshCode`.
             */
            $table->string('code', 8)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('streetmesh_parties', function (Blueprint $table): void {
            /*
             * The index first, or SQLite refuses: it rebuilds the table to drop
             * a column and then finds an index naming one that is no longer
             * there. The error names the index, which reads as the index being
             * the problem rather than the order.
             */
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
