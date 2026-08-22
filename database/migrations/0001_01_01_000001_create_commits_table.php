<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chain of what each resident has committed to.
 *
 * Append-only, and the ordering column is the commit's own revision rather than
 * a row id — so the history reads the same whichever server is holding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_commits', function (Blueprint $table): void {
            $table->id();

            $table->string('did')->index();

            // The commit's own name, which the next one points at.
            $table->string('cid', 59)->unique();

            // The one before it. Null exactly once per resident.
            $table->string('prev', 59)->nullable();

            // Root of the record tree this commit covers.
            $table->string('data', 59);

            // When, as a record key, so commits sort like everything else does.
            $table->string('rev', 13);

            /*
             * The commit exactly as it travels, base64 of its own bytes. Not
             * JSON: a commit is named by the hash of precisely these bytes, so
             * storing a decoded copy and re-encoding it later would mean
             * trusting two encoders to agree.
             */
            $table->text('body');

            $table->timestamp('created_at');

            // Reading a resident's history in order, and finding its head.
            $table->unique(['did', 'rev']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_commits');
    }
};
