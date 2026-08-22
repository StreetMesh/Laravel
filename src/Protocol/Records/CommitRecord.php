<?php

namespace StreetMesh\Server\Protocol\Records;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One link in a resident's history, as stored.
 *
 * @property int $id
 * @property string $did
 * @property string $cid
 * @property string|null $prev
 * @property string $data
 * @property string $rev
 * @property string $body the commit as it travels, base64
 * @property Carbon $created_at
 */
class CommitRecord extends Model
{
    protected $table = 'streetmesh_commits';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A history that can be edited is not a history. Correcting one means
        // committing again, which leaves both the mistake and the correction.
        static::updating(function (CommitRecord $commit): void {
            throw new RuntimeException(
                "Commit [{$commit->cid}] cannot be altered. A history that can be edited proves nothing."
            );
        });
    }
}
