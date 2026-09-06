<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a resident calls one of their own.
 *
 * A third column that is theirs rather than the record's, beside `is_default`
 * and `deleted_at`. All three answer questions a record cannot: which of these
 * am I wearing, which am I still choosing between, and what do I call this one.
 *
 * Renaming could not be done by writing the record again, and the reason is
 * worth keeping. A record is written once, so a new name would mean a new
 * record — a new `rkey`, and `written_by` gone with it, because that is set by
 * the endpoint that received the claim and a resident writing their own has
 * nobody to name. Renaming an avatar built at a venue would quietly erase where
 * it was built. A name is not worth that.
 *
 * So `name` stays exactly what the record says and is still rebuilt by
 * replaying it, which is the promise the first of these migrations made. This
 * sits beside it and is never derived from anything.
 *
 * Nullable, and null is not the same as empty: it means nobody has renamed this
 * one, so the record's own word stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->string('alias', 64)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->dropColumn('alias');
        });
    }
};
