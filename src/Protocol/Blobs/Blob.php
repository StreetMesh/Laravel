<?php

namespace StreetMesh\Server\Protocol\Blobs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use StreetMesh\Server\Protocol\Records\Record;

/**
 * A file somebody holds, named by what is in it.
 *
 * The row is a record of bytes rather than the bytes themselves; those are on a
 * disk, at a path this derives. That split is deliberate — a picture belongs in
 * object storage where an operator can put a CDN in front of it, and a database
 * that holds megabytes of image is a database that cannot be backed up quickly.
 *
 * Like a record, a blob is written once. Unlike a record, the reason is not
 * about citation but about arithmetic: the name *is* the content, so a blob
 * whose bytes changed would simply be a different blob under a name that no
 * longer described it.
 *
 * @property int $id
 * @property string $did
 * @property string $cid
 * @property string $mime
 * @property int $size
 * @property string $collection
 * @property string $visibility
 * @property Carbon $created_at
 */
class Blob extends Model
{
    protected $table = 'streetmesh_blobs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * What anybody may fetch, as opposed to what this server merely holds.
     *
     * @param  Builder<Blob>  $query
     */
    public function scopeVisibleToStrangers(Builder $query): void
    {
        $query->where('visibility', Record::PUBLIC);
    }

    /**
     * Where the bytes sit on whichever disk is configured.
     *
     * Under the subject as well as the content, so that one person's storage
     * can be counted, exported or deleted by looking in one place — and so
     * that two residents holding the same picture are two files rather than
     * one, which is what makes deleting either of them safe.
     */
    public function path(): string
    {
        return 'streetmesh/blobs/'.sha1($this->did).'/'.$this->cid;
    }
}
