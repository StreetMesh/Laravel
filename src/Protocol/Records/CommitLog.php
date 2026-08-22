<?php

namespace StreetMesh\Server\Protocol\Records;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use StreetMesh\Protocol\Commit;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\RecordTree;

/**
 * Every change to somebody's records, signed and in order.
 *
 * A record on its own says only that it has not been altered since it was named.
 * The chain is what says the person it belongs to committed to it, and that the
 * set is the one they committed to rather than one their server assembled.
 *
 * Worth repeating where it will be read: this does not stop a server lying about
 * its residents, because the server holds the key. It makes lying leave a mark.
 * A substituted past produces a link that does not fit, so anybody who saw the
 * earlier link — another server, a visitor, the resident's own backup — can show
 * the two histories disagree.
 */
final class CommitLog
{
    public function __construct(private readonly RecordTree $tree) {}

    /**
     * Record that this is now the whole of somebody's records.
     *
     * Called after a write rather than instead of one, so a record exists before
     * anything commits to it — a chain that referenced records not yet stored
     * would be a chain of claims.
     */
    public function commit(string $did, Ed25519 $key): CommitRecord
    {
        return DB::transaction(function () use ($did, $key): CommitRecord {
            /*
             * Locked, because two writes committing at once would each build a
             * chain from the same parent and produce a fork this server made
             * itself — which is precisely the thing the chain exists to make
             * visible in others.
             */
            $head = CommitRecord::query()
                ->where('did', $did)
                ->orderByDesc('rev')
                ->lockForUpdate()
                ->first();

            $root = $this->tree->root($this->fingerprints($did));

            $commit = Commit::of($did, (string) $root, prev: $head?->cid)->signedWith($key);

            return CommitRecord::create([
                'did' => $did,
                'cid' => (string) $commit->cid(),
                'prev' => $commit->prev === null ? null : (string) $commit->prev,
                'data' => (string) $commit->data,
                'rev' => $commit->rev,

                /*
                 * Kept as the bytes it travels as rather than as JSON. A commit
                 * is named by the hash of exactly those bytes, so storing a
                 * decoded copy and re-encoding it later would be trusting two
                 * encoders to agree — which is the mistake this whole design
                 * exists to avoid.
                 */
                'body' => base64_encode($commit->toBytes()),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Read a resident's history and check it hangs together.
     *
     * Three questions, and all three have to hold: is every commit signed by
     * them, does each name the one before it, and does the head still describe
     * the records actually here.
     */
    public function verify(string $did, string $multikey): void
    {
        $chain = CommitRecord::query()->where('did', $did)->orderBy('rev')->get();

        if ($chain->isEmpty()) {
            return;
        }

        $previous = null;

        foreach ($chain as $link) {
            $commit = Commit::fromBytes((string) base64_decode($link->body, true));

            if (! $commit->verify($multikey)) {
                throw new RuntimeException("Commit [{$link->cid}] is not signed by [{$did}].");
            }

            if ($previous === null) {
                if ($commit->prev !== null) {
                    throw new RuntimeException("The history of [{$did}] begins by naming a commit that is not here.");
                }
            } elseif (! $commit->follows($previous)) {
                throw new RuntimeException(
                    "Commit [{$link->cid}] does not follow the one before it. The history has been rewritten."
                );
            }

            $previous = $commit;
        }

        $root = (string) $this->tree->root($this->fingerprints($did));

        if ((string) $previous->data !== $root) {
            /*
             * The chain is intact but no longer describes what is here, which
             * means records were added or removed without committing to them.
             */
            throw new RuntimeException(
                "The records held for [{$did}] are not the ones its history commits to."
            );
        }
    }

    public function head(string $did): ?CommitRecord
    {
        return CommitRecord::query()->where('did', $did)->orderByDesc('rev')->first();
    }

    /**
     * Every record this resident has, as address and content hash.
     *
     * Private records are included. Committing only to the public ones would
     * mean a server could add or drop a private record with nothing to show for
     * it, and the people most in need of that protection are the ones whose
     * records are private.
     *
     * @return array<string, string>
     */
    private function fingerprints(string $did): array
    {
        return Record::query()
            ->where('did', $did)
            ->orderBy('collection')
            ->orderBy('rkey')
            ->get(['collection', 'rkey', 'cid'])
            ->mapWithKeys(fn (Record $record): array => [
                $record->collection.'/'.$record->rkey => $record->cid,
            ])
            ->all();
    }
}
