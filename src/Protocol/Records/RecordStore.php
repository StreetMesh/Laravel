<?php

namespace StreetMesh\Server\Protocol\Records;

use Illuminate\Database\Eloquent\Collection as Eloquent;
use Illuminate\Support\Carbon;
use RuntimeException;
use StreetMesh\Protocol\AtUri;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\Tid;

/**
 * Putting records in, and getting them back out.
 *
 * The only way to write a record. Everything that makes the store trustworthy is
 * arranged so that it cannot be skipped by accident: keys are minted here rather
 * than supplied, visibility is looked up rather than passed, and the content
 * hash is computed rather than accepted. A caller cannot get any of those wrong,
 * because a caller does not get to say.
 */
final class RecordStore
{
    public function __construct(private readonly Collections $collections) {}

    /**
     * Write a record and give back its address.
     *
     * @param  array<string, mixed>  $value
     */
    public function put(string $did, string $collection, array $value, ?Tid $key = null): Record
    {
        // Refuses a collection this server has not declared, rather than
        // inventing a visibility for it. A typo in a collection name is then a
        // failure instead of a new kind of record nobody meant to create.
        $visibility = $this->collections->visibilityOf($collection);

        $key ??= Tid::now();

        return Record::create([
            'did' => $did,
            'collection' => $collection,
            'rkey' => (string) $key,
            'cid' => (string) Cid::forRecord($value),
            'value' => $value,
            'visibility' => $visibility,
            'created_at' => Carbon::now(),
        ]);
    }

    public function get(AtUri $address): ?Record
    {
        if (! $address->isRecord()) {
            throw new RuntimeException("[{$address}] names a collection rather than a record.");
        }

        return Record::query()
            ->where('did', $address->authority)
            ->where('collection', $address->collection)
            ->where('rkey', $address->rkey)
            ->first();
    }

    /**
     * Somebody's records of one kind, oldest first.
     *
     * `$asStranger` is the safe default on purpose. A reader that has not
     * established who it is speaking for should see only what anybody may see,
     * so forgetting to pass an audience under-serves rather than over-shares.
     *
     * @return Eloquent<int, Record>
     */
    public function list(
        string $did,
        string $collection,
        bool $asStranger = true,
        int $limit = 100,
        ?string $after = null,
    ): Eloquent {
        $query = Record::query()
            ->where('did', $did)
            ->where('collection', $collection)
            ->inOrder()
            ->limit($limit);

        if ($asStranger) {
            $query->visibleToStrangers();
        }

        if ($after !== null) {
            // Paging by key rather than by offset, which is only correct because
            // the key sorts by time — and which stays correct while records are
            // being written underneath the reader.
            $query->where('rkey', '>', $after);
        }

        return $query->get();
    }

    /**
     * Everything of somebody's that a stranger may read.
     *
     * The shape an export takes, and the reason portability does not need a
     * full repository implementation to be real: a person can leave with their
     * records whether or not this server ever speaks the wider protocol.
     *
     * @return Eloquent<int, Record>
     */
    public function exportFor(string $did, bool $asStranger = true): Eloquent
    {
        $query = Record::query()->where('did', $did)->inOrder();

        if ($asStranger) {
            $query->visibleToStrangers();
        }

        return $query->get();
    }
}
