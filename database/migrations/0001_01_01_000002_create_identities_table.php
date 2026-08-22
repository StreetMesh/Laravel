<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who this server is, and who lives on it.
 *
 * Servers and residents are the same kind of thing here — both have an
 * identifier, keys, and a name people can type — so both live in one table and
 * differ only in what they are for. That is not tidiness: a venue signs
 * attestations exactly as a resident signs commits, and giving them separate
 * machinery would mean two chances to get signing wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_identities', function (Blueprint $table): void {
            $table->id();

            $table->string('did')->unique();

            // The readable name, which points at the DID and may change while it
            // does not. Null for an identity nobody types.
            $table->string('handle')->nullable()->unique();

            /*
             * The key used day to day, encrypted at rest. Held by this server on
             * its subject's behalf, which is why a commit proves attribution and
             * not honesty — see CommitLog.
             */
            $table->text('signing_key');

            $table->string('signing_curve', 16);

            /*
             * The key that can rewrite the DID document, including replacing the
             * signing key and pointing the identity at another server. Nullable
             * because this server may not hold one — and for a resident it
             * should not be the only one, or leaving becomes a favour this
             * server grants.
             */
            $table->text('rotation_key')->nullable();

            // Whether this identity belongs to the server itself or to somebody
            // it hosts. One row per server, many per resident.
            $table->boolean('is_server')->default(false);

            /*
             * Whatever the host application attaches this to — a user, usually.
             * The protocol does not care who somebody is beyond their keys, so
             * names, passwords and avatars stay where they belong.
             */
            $table->nullableMorphs('owner');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_identities');
    }
};
