<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a gathering ended, kept by the venue.
 *
 * The hub does not keep it. A room is memory and is gone the moment the last
 * person leaves, so a venue that recorded only "concluded" could say a game was
 * over and nothing else — a finished board could not be shown to somebody
 * coming back to look at it, and a list of what has happened here would be a
 * list of names.
 *
 * The venue's own copy, not the authority. What each participant holds on their
 * own server is that; this is what lets this screen say the same thing without
 * having to ask them.
 *
 * Its shape is the experience's business. A venue has no opinion about what a
 * result looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_gatherings', function (Blueprint $table): void {
            $table->json('outcome')->nullable()->after('concluded_at');
        });
    }

    public function down(): void
    {
        Schema::table('streetmesh_gatherings', function (Blueprint $table): void {
            $table->dropColumn('outcome');
        });
    }
};
