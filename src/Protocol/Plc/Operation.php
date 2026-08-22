<?php

namespace StreetMesh\Server\Protocol\Plc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One signed operation in an identity's chain.
 *
 * @property int $id
 * @property string $did
 * @property string $cid
 * @property string|null $prev
 * @property array<string, mixed> $operation
 * @property bool $nullified
 * @property Carbon $created_at
 */
class Operation extends Model
{
    protected $table = 'streetmesh_plc_operations';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'operation' => 'array',
            'nullified' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * How this operation appears in an audit log.
     *
     * Shaped after what the public directory returns, field for field, because
     * the client reading it is the same client that reads that one — and a
     * local directory whose log needed special handling would be a local
     * directory that proves nothing about production.
     *
     * @return array<string, mixed>
     */
    public function asLogEntry(): array
    {
        return [
            'did' => $this->did,
            'operation' => $this->operation,
            'cid' => $this->cid,
            'nullified' => $this->nullified,
            'createdAt' => $this->created_at->toIso8601ZuluString('microseconds'),
        ];
    }
}
