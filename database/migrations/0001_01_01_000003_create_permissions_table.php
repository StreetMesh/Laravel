<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a venue asked for, and what a resident said about it.
 *
 * One table for the whole life of a permission rather than three, because it is
 * one thing at four ages: pushed, approved, granted, withdrawn. Splitting it
 * would mean a row moving between tables at each step, and a moment during each
 * move when it is in both or neither.
 *
 * Nothing here is a secret shared with the venue. There is no client secret to
 * store because there is none to share — a venue authenticates by signing, and
 * its keys are fetched when they are needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_permissions', function (Blueprint $table): void {
            $table->id();

            /*
             * The handle a venue is known by, which is the URL its metadata is
             * served from. Stored rather than referenced because it is the
             * name — there is no clients table for it to point at, and that
             * absence is the design rather than an omission.
             */
            $table->string('client_id');

            // Whose permission this is. Null until somebody has actually said
            // yes, because a pushed request names a venue and not yet a person.
            $table->string('did')->nullable()->index();

            /*
             * The three secrets, each replacing the one before it. A request
             * becomes a code becomes a token, and only one of them is ever
             * live — hashed, because a leaked table should not be a leaked
             * account.
             */
            $table->string('request_uri')->nullable()->unique();
            $table->string('code_hash')->nullable()->unique();
            $table->string('token_hash')->nullable()->unique();
            $table->string('refresh_hash')->nullable()->unique();

            // Which key the tokens are bound to. A token presented without a
            // proof from this key is somebody else's.
            $table->string('thumbprint')->nullable();

            // What was asked for, and where to come back to.
            $table->string('scope');
            $table->string('redirect_uri');
            $table->string('state')->nullable();

            // The hashed half of PKCE, kept so the verifier can be checked
            // against it when the code is spent.
            $table->string('code_challenge');

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            /*
             * Each stage has its own deadline and they are very different: a
             * pushed request is good for a minute, a code for seconds, a token
             * for under an hour. One column would have to mean all three.
             */
            $table->timestamp('request_expires_at')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamps();

            $table->index(['did', 'client_id']);
        });

        /*
         * Identifiers already spent, so a replay can be refused.
         *
         * Proofs and assertions are single-use and short-lived, and neither can
         * be checked for reuse without somewhere to remember what has been
         * seen. Rows here are worthless the moment they expire and are meant to
         * be swept.
         */
        Schema::create('streetmesh_spent', function (Blueprint $table): void {
            $table->id();
            $table->string('jti')->unique();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_spent');
        Schema::dropIfExists('streetmesh_permissions');
    }
};
