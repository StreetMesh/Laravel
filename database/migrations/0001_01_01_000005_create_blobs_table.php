<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bytes that are not a structure.
 *
 * A record says something and can be read; a blob is a picture, a model, a
 * file — bytes whose meaning is not the database's business and never will be.
 * They are kept apart from records for that reason rather than for size: a
 * record's value is queried, versioned and exported as JSON, and none of those
 * are things worth doing to a PNG.
 *
 * What the two share is naming. A blob is addressed by the hash of its content
 * exactly as a record is, so a reference to one means "those bytes" rather than
 * "whatever is at that name now", and two servers holding the same picture
 * agree about what it is called without asking each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_blobs', function (Blueprint $table): void {
            // For the ORM, and for nothing else — see the records table, which
            // makes the same point at length.
            $table->id();

            // Whose bytes these are. A DID, so they still name their subject
            // after either of them has moved.
            $table->string('did')->index();

            /*
             * The content, hashed, under the multicodec `raw` — so this reads
             * `bafkrei…` where a record reads `bafyrei…`. Same length, and
             * that is the trap: only the codec byte distinguishes them.
             */
            $table->string('cid', 59);

            /*
             * What the bytes turned out to be, decided by looking at them.
             * Never what an uploader said they were: a caller that could name
             * the type could serve a script from somebody's own hostname.
             */
            $table->string('mime', 128);

            // Kept so that listing what somebody is storing does not mean
            // asking the disk about every file.
            $table->unsignedBigInteger('size');

            /*
             * What these bytes were kept *for* — the same NSID a record of the
             * thing referring to them goes in.
             *
             * Present so that the next two columns can exist. Bytes on their
             * own carry no hint of who may read them, and the alternatives were
             * both worse: a visibility passed in by the caller is an input that
             * can be wrong, and deriving it by searching record bodies for the
             * name means querying the interior of a value this schema promises
             * never to look inside.
             */
            $table->string('collection');

            /*
             * Looked up from that collection's declaration, exactly as a
             * record's is, and stored per row for the same reason: reading is
             * one query rather than a query and a lookup.
             *
             * So a picture kept for a public kind of thing is public, and bytes
             * kept for anything else are not served to strangers. The rule is
             * the records table's rule, which is the point — a second, subtly
             * different answer to "who may see this" is how the two drift.
             */
            $table->string('visibility', 16);

            $table->timestamp('created_at');

            /*
             * One row per person per content. Storing the same picture twice
             * under one subject is not a second blob, it is the same blob
             * being referred to again — and the name already says so.
             */
            $table->unique(['did', 'cid']);

            // Serving a stranger without reading and discarding everything
            // they may not have.
            $table->index(['did', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_blobs');
    }
};
