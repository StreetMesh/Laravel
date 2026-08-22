<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A few people who came here together, and stay together while they are here.
 *
 * A party is a gathering turned on its side. A gathering is anchored to
 * something happening — one game, one auction — and everybody in it is there
 * for that. A party is anchored to nobody and nothing: it crosses every
 * experience at this venue, outlives each of them, and ends only when the last
 * person walks out.
 *
 * That is why this is its own table rather than a gathering with a flag. The
 * two are the same shape and have opposite lifetimes, and a column that meant
 * "ignore the experience column" would be a gathering pretending.
 *
 * Nothing here is durable in the way a gathering is. A party writes no record,
 * concludes nothing and is signed about by nobody — it exists so that a venue
 * can remember who is together across a page load, which the hub cannot do
 * because the hub forgets everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_parties', function (Blueprint $table): void {
            $table->id();

            /*
             * What an invitation names, and what the hub knows the party's room
             * by. A ULID for the same reason a gathering's key is one: it goes
             * out into the world in a link and has to survive the trip.
             */
            $table->string('key')->unique();

            /*
             * Two states and no outcome, so a timestamp says it all.
             *
             * A gathering carries a status column because it has somewhere to
             * end up — concluded, with a result somebody signs. A party has no
             * result. It is over when it is empty, and the only thing worth
             * knowing afterwards is when that was.
             */
            $table->timestamp('disbanded_at')->nullable();

            $table->timestamps();
        });

        Schema::create('streetmesh_party_members', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('party_id')->constrained('streetmesh_parties')->cascadeOnDelete();

            /*
             * Held against the permission rather than the person, exactly as a
             * seat is. A visitor is here on borrowed authority and a party is
             * no more entitled to outlive that than a chair is.
             */
            $table->foreignId('delegation_id')->constrained('streetmesh_delegations')->cascadeOnDelete();

            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'delegation_id']);
        });

        Schema::create('streetmesh_party_invitations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('party_id')->constrained('streetmesh_parties')->cascadeOnDelete();

            /*
             * Who is invited, as a DID rather than as a delegation.
             *
             * An invitation is offered to a person and answered minutes later,
             * and in between they may have reloaded — which mints them a fresh
             * delegation and would leave the invitation addressed to a trip
             * through the door that is over. Seats learned this the expensive
             * way: keyed on the delegation, one returning human became two
             * people at one table.
             */
            $table->string('did');

            /** Who offered it, so a screen can say who is asking. */
            $table->string('invited_by_did');
            $table->string('invited_by_name')->default('');

            /*
             * An invitation nobody answered is not a standing offer. It goes
             * stale on its own, so a roster does not fill with knocks from
             * people who left an hour ago.
             */
            $table->timestamp('expires_at');

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();

            $table->timestamps();

            /*
             * One open invitation per person per party. Inviting somebody twice
             * is the same invitation, not a second one — and a roster showing
             * the same knock three times is a venue that looks broken.
             */
            $table->unique(['party_id', 'did']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_party_invitations');
        Schema::dropIfExists('streetmesh_party_members');
        Schema::dropIfExists('streetmesh_parties');
    }
};
