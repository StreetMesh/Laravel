<?php

namespace StreetMesh\Server\Domicile\Avatars;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property string|null $written_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Avatar extends Model
{
    /**
     * Put away rather than destroyed.
     *
     * A record is written once and stands forever, so the only thing a resident
     * can discard is this — their own answer to which faces they are still
     * choosing between. The record and the picture it names are untouched, and
     * a projection is rebuildable by replaying them.
     */
    use SoftDeletes;

    protected $table = 'streetmesh_avatars';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Where this was built, as an address somebody can visit.
     *
     * `written_by` is a DID, and `did:web:avatars.streetmesh.com` is a hostname
     * with a prefix -- that is the whole of what makes did:web resolvable, and
     * it is what makes this a link rather than an identifier. Null for an
     * avatar somebody uploaded themselves: nobody else was involved.
     */
    public function builtAt(): ?string
    {
        $did = (string) $this->written_by;

        return str_starts_with($did, 'did:web:')
            ? 'https://'.substr($did, strlen('did:web:'))
            : null;
    }

    /**
     * The picture itself, or null if this server has lost it.
     */
    public function icon(): ?Blob
    {
        return app(BlobStore::class)->get($this->did, $this->icon_cid);
    }

    /**
     * The body, or null -- which means either that nobody has built one or
     * that this server has lost it.
     *
     * The two are not distinguished on purpose. To whoever asked they are the
     * same answer, and a caller that had to tell them apart would be a caller
     * doing something about a difference it cannot act on.
     */
    public function model(): ?Blob
    {
        return $this->model_cid === null
            ? null
            : app(BlobStore::class)->get($this->did, $this->model_cid);
    }
}
