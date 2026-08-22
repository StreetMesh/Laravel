<?php

namespace StreetMesh\Server\Domicile\Avatars;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use StreetMesh\Server\Protocol\Blobs\Blob;
use StreetMesh\Server\Protocol\Blobs\BlobStore;

/**
 * One of somebody's faces, as this server indexes it.
 *
 * A row here is not the fact — the record is. This is the answer to the
 * question a record cannot answer quickly: which of the several a resident has
 * written is the one to draw right now.
 *
 * @property int $id
 * @property string $did
 * @property string $rkey
 * @property string $name
 * @property string $icon_cid
 * @property string|null $model_cid
 * @property bool $is_default
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Avatar extends Model
{
    protected $table = 'streetmesh_avatars';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * The picture itself, or null if this server has lost it.
     */
    public function icon(): ?Blob
    {
        return app(BlobStore::class)->get($this->did, $this->icon_cid);
    }
}
