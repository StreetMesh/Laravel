<?php

namespace StreetMesh\Server\Protocol\Records;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use StreetMesh\Protocol\AtUri;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\Tid;

/**
 * One thing that happened to somebody, as they hold it.
 *
 * Written once and never again. That is not caution — it is what makes a
 * reference to a record mean anything. If a record could change after being
 * cited, then "the game recorded at this address" would name whatever is there
 * now rather than what was there then, and every signature over it, every
 * dispute about it and every copy of it elsewhere would drift apart silently.
 *
 * A correction is therefore a new record that says what it corrects, and the
 * old one stays. History accumulates rather than being edited, which is also
 * the only version of history a second party can check.
 *
 * @property int $id
 * @property string $did
 * @property string $collection
 * @property string $rkey
 * @property string $cid
 * @property array<string, mixed> $value
 * @property string $visibility
 * @property Carbon $created_at
 */
class Record extends Model
{
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    protected $table = 'streetmesh_records';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * The immutability is enforced here rather than trusted to callers,
         * because "we agreed not to update these" survives exactly as long as
         * the first person who has not read the agreement and has a deadline.
         */
        static::updating(function (Record $record): void {
            throw new RuntimeException(
                "Record [{$record->address()}] has already been written. A correction is a new record that "
                .'says what it corrects; editing this one would change what everybody who cited it was citing.'
            );
        });

        /*
         * Deleting is allowed — a person may withdraw what is theirs, and a
         * system that cannot forget is its own kind of problem — but it is a
         * deletion, not a quiet substitution.
         */
    }

    public function address(): AtUri
    {
        return AtUri::make($this->did, $this->collection, $this->rkey);
    }

    public function key(): Tid
    {
        return Tid::parse($this->rkey);
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    /**
     * Does this record still say what it said when it was named?
     *
     * Cheap, and worth running on anything that has been anywhere — a record
     * whose CID no longer matches its value has been altered underneath the
     * name everybody else knows it by.
     */
    public function isIntact(): bool
    {
        return Cid::parse($this->cid)->matches($this->value);
    }

    /**
     * @param  Builder<Record>  $query
     * @return Builder<Record>
     */
    public function scopeVisibleToStrangers(Builder $query): Builder
    {
        return $query->where('visibility', self::PUBLIC);
    }

    /**
     * @param  Builder<Record>  $query
     * @return Builder<Record>
     */
    public function scopeInOrder(Builder $query, string $direction = 'asc'): Builder
    {
        // The key sorts by time, so this needs no separate timestamp column and
        // agrees with any other implementation reading the same records.
        return $query->orderBy('rkey', $direction);
    }
}
