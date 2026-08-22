<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something happening here, and who is part of it.
 *
 * A chess game, a watch party, an auction. The hub holds the live version of
 * one and forgets it the moment everybody leaves; this is the half that
 * remembers — that a game was started, who sat down, and how it ended.
 *
 * The split is the whole architecture in one table. A room is fast and
 * disposable. A gathering is slow and durable, and it is what a ticket is
 * checked against, what a record is signed about, and what is still here after
 * a restart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_gatherings', function (Blueprint $table): void {
            $table->id();

            /*
             * Which experience, by the name its author gave it. An NSID, so two
             * experiences by different authors cannot collide without somebody
             * doing it deliberately — the same rule collections follow.
             */
            $table->string('experience');

            /*
             * Which one of them. Together with the experience this is the name
             * the hub knows a room by, and the name a ticket is minted against.
             */
            $table->string('key')->unique();

            $table->string('status')->default('open');

            $table->timestamp('concluded_at')->nullable();

            $table->timestamps();

            $table->index(['experience', 'status']);
        });

        Schema::create('streetmesh_gathering_seats', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('gathering_id')->constrained('streetmesh_gatherings')->cascadeOnDelete();

            /*
             * Which permission this seat is held under, rather than which
             * person. A visitor is only ever here on borrowed authority, and
             * when that is withdrawn the seat should go with it rather than
             * outliving the thing that justified it.
             */
            $table->foreignId('delegation_id')->constrained('streetmesh_delegations')->cascadeOnDelete();

            /*
             * Empty for somebody present but not playing — an audience. A named
             * seat is unique within a gathering, because two people cannot both
             * be white.
             */
            $table->string('seat')->default('');

            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['gathering_id', 'delegation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_gathering_seats');
        Schema::dropIfExists('streetmesh_gatherings');
    }
};
