<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a resident looks like, indexed.
 *
 * Not the durable fact — that is a record, written once and kept forever, and
 * changing your avatar writes a new one rather than editing the old. This is
 * the projection beside it: the answer to "which one is theirs now", which a
 * record cannot give because records accumulate and because nothing is allowed
 * to query the interior of a record's value.
 *
 * So the rule the records table states is honoured here rather than bent. Every
 * column below is derived from a record, and every one of them could be rebuilt
 * by replaying them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_avatars', function (Blueprint $table): void {
            $table->id();

            // Whose face this is.
            $table->string('did')->index();

            // The record it was projected from, so the two can be reconciled.
            $table->string('rkey', 13);

            // What they call it. "Weekday", "Soldier" — a label for a person
            // choosing between their own, never shown to anybody else.
            $table->string('name');

            /*
             * The picture, by content. Two columns rather than one shape,
             * because they answer different questions and arrive at different
             * times: an icon is what 2D places draw, and a model is what
             * spatial ones do.
             */
            $table->string('icon_cid', 59);

            // Null until models are built. Present now so that filling it in
            // later is a write rather than a migration of everybody's avatars.
            $table->string('model_cid', 59)->nullable();

            /*
             * Which one is them.
             *
             * A column rather than a single-row table, because a resident is
             * going to have several and exactly one of those is the one a party
             * draws. Today the uniqueness below allows only one avatar at all;
             * a collection arrives by relaxing that constraint, and this column
             * already means the right thing when it does.
             */
            $table->boolean('is_default')->default(true);

            $table->timestamps();

            /*
             * One avatar per resident, for now. The line to change when a
             * resident may keep several — at which point the pair becomes
             * (did, rkey) and `is_default` starts earning its keep.
             */
            $table->unique('did');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_avatars');
    }
};
