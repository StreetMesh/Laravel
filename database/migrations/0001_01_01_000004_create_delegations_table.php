<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission this server holds over somebody else's records.
 *
 * The mirror of `streetmesh_permissions`, which is permission this server has
 * granted. A server that is both a domicile and a venue has both tables and they
 * do not touch: one is what strangers may do here, the other is what we may do
 * elsewhere. Keeping them apart means neither can be mistaken for the other by a
 * query that forgot which side it was on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_delegations', function (Blueprint $table): void {
            $table->id();

            /*
             * Who this is permission over, and where their server is. The handle
             * is kept because it is what a person typed and what should be shown
             * back to them; the DID is what everything else is keyed on, because
             * it survives them changing the handle.
             */
            $table->string('did')->nullable()->index();
            $table->string('handle');
            $table->string('issuer');

            /*
             * The key this delegation is bound to. Generated per delegation
             * rather than per server, so a token stolen from one visitor is
             * useless for another, and kept because every request made with the
             * token has to be signed by it.
             */
            $table->text('dpop_key');

            /*
             * Held between the two halves of the exchange and cleared the moment
             * the code is spent. The verifier is the secret that makes an
             * intercepted code worthless, so it exists here for seconds.
             */
            $table->string('state')->nullable()->unique();
            $table->text('code_verifier')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('scope');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['did', 'issuer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_delegations');
    }
};
