<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taking one out of the wardrobe, and remembering where it was made.
 *
 * Two columns, both derived from things that already exist.
 *
 * `deleted_at` removes an avatar from somebody's wardrobe without removing it
 * from their history, which is the only kind of deleting available here: a
 * record is written once and stands forever, so what a person is discarding is
 * the projection — their own answer to "which of these am I still choosing
 * between". The record, and the picture it names, are untouched.
 *
 * `written_by` is the venue that wrote it, taken from the claim itself. A face
 * built at a venue carries the name of the server that carried it, and a
 * resident looking at four of them has a fair question about where each came
 * from — and, more usefully, somewhere to go back to and build another.
 * Null for an avatar somebody uploaded themselves, which is the honest answer:
 * nobody else was involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->softDeletes();

            $table->string('written_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('written_by');
        });
    }
};
