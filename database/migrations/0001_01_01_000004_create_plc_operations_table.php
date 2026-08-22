<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operations a PLC directory holds, when this server is one.
 *
 * A `did:plc` is the hash of the operation that created it, and everything
 * after that is a chain: each operation names the one before it by CID and is
 * signed by a key the subject holds. A directory therefore cannot forge an
 * identity, invent one, or reassign one — it can only decline to answer, which
 * is why running one is a smaller act of trust than it sounds.
 *
 * This table is the whole of it. There is no state a directory holds beyond the
 * operations themselves: the DID document it serves is derived from the head of
 * each chain every time it is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streetmesh_plc_operations', function (Blueprint $table): void {
            $table->id();

            $table->string('did')->index();

            /*
             * The CID of the signed operation, which is what the next one in
             * the chain names as its `prev`. Unique because two operations
             * with one identifier would be the chain forking silently.
             */
            $table->string('cid')->unique();

            /** Null for the operation an identity was born from. */
            $table->string('prev')->nullable();

            $table->json('operation');

            /*
             * Undone by a recovery using a higher-priority rotation key. Kept
             * rather than deleted so the recovery stays auditable, and skipped
             * when reading history — an entry that was nullified never
             * happened as far as the record is concerned.
             */
            $table->boolean('nullified')->default(false);

            $table->timestamp('created_at');

            /*
             * The head of a chain is the newest operation for a DID that has
             * not been nullified, and that is the question asked on every read
             * and every write.
             */
            $table->index(['did', 'nullified', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streetmesh_plc_operations');
    }
};
