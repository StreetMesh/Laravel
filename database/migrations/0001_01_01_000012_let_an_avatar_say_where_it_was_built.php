<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an avatar can be opened again and changed.
 *
 * A projection of `editableAt` on the record, and like every other column here
 * it could be rebuilt by replaying them. Nullable because most avatars have no
 * such place: one somebody uploaded themselves was never built anywhere, and a
 * venue is free not to offer it.
 *
 * Held as written rather than as parts, because it is the venue's own route and
 * this server has no business having opinions about its shape. What this server
 * does have an opinion about is whether to *show* it, which is a question about
 * the host it points at and is answered at the point of use — see
 * `Avatar::editableAt()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->string('editable_at', 2048)->nullable()->after('model_cid');
        });
    }

    public function down(): void
    {
        Schema::table('streetmesh_avatars', function (Blueprint $table): void {
            $table->dropColumn('editable_at');
        });
    }
};
