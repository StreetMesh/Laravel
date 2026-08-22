<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a resident's records live.
 *
 * Shaped like a repository rather than like a table, so that serving one later
 * is a second reader over the same rows instead of a rewrite. That means an
 * address of (subject, collection, key) rather than an incrementing id, a value
 * the database never looks inside, and rows that are written once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_records', function (Blueprint $table): void {
            /*
             * Present for the ORM's benefit and nothing else. Nothing outside
             * this table may reference it, because it is the one part of a
             * record's identity that cannot survive being moved to another
             * server — which is the whole thing being protected here.
             */
            $table->id();

            // Whose record this is. A DID rather than a local user id, so the
            // record still names its subject after either of them has moved.
            $table->string('did')->index();

            // What kind of record. The schema's name, so meaning is part of
            // where a record lives rather than a field inside it.
            $table->string('collection');

            // Which one, as a key that sorts by the moment it was made.
            $table->string('rkey', 13);

            // What it says, hashed. Lets a reference mean "that record, as it
            // was" instead of "whatever is at that address now".
            $table->string('cid', 59);

            /*
             * The record itself. Opaque on purpose: no foreign keys into it, no
             * queries against its interior, no columns derived from it that
             * anything relies on. Anything needing an index gets a projection
             * built alongside, never a join through here.
             */
            $table->json('value');

            /*
             * Set from the collection's declaration rather than from anything a
             * caller passes, so there is no input that could promote a private
             * record. Stored per row so that reading is one query rather than a
             * query plus a lookup.
             */
            $table->string('visibility', 16);

            // When the record was written here, which may be long after the
            // event it describes.
            $table->timestamp('created_at');

            /*
             * The real identity of a record. Unique because an address names
             * exactly one thing, and ordered this way because listing a
             * subject's history of one kind, oldest or newest first, is the
             * query this table exists to serve.
             */
            $table->unique(['did', 'collection', 'rkey']);

            // Serving only what a visitor may see, without reading and
            // discarding the rest.
            $table->index(['did', 'visibility', 'collection', 'rkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_records');
    }
};
