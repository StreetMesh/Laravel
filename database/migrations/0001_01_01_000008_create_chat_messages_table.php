<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talking, in a particular place.
 *
 * Spaces are addressed by path, so a lobby, a table and a party are the same
 * kind of thing — somewhere you can be, and somewhere you can be pointed at.
 * That is the whole of the model: post a message to a space, and everybody in
 * that space sees it.
 *
 * Deliberately not in the room. Chat is social and durable and adjudicates
 * nothing, so it belongs to the half of the server that remembers things —
 * which also means it survives the hub restarting, and that a table people came
 * back to still has what was said at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_chat_messages', function (Blueprint $table): void {
            $table->id();

            /*
             * Which space, by its path. `/lobby`, a table's, a party's.
             *
             * A string rather than a relation, because the things it names are
             * not one kind of row: a gathering is a record here, a party is
             * another, and the lobby is not a row at all. What they have in
             * common is that they are somewhere you can be, and a path is what
             * that looks like written down.
             */
            $table->string('space')->index();

            /*
             * Who said it, kept as text rather than as a foreign key.
             *
             * A visitor is here on a delegation, and a delegation is deleted the
             * moment they give their permission back. Hung off that, a whole
             * conversation would vanish when one participant left — so what is
             * stored is who they were, which is what a transcript needs anyway.
             *
             * The name is the one they had when they said it, and that is the
             * right answer rather than a stale one. A message is a thing that
             * happened at a time, and re-labelling old utterances with somebody's
             * new handle would be editing the past to be tidy.
             */
            $table->string('did');
            $table->string('name');

            $table->text('body');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_chat_messages');
    }
};
