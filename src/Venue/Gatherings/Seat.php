<?php

namespace StreetMesh\Server\Venue\Gatherings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use StreetMesh\Server\Protocol\Permissions\Delegation;

/**
 * Somebody's place in a gathering.
 *
 * Held against a delegation rather than a person, because a visitor is only
 * ever here on borrowed authority. When that is withdrawn the seat goes with
 * it, rather than outliving the thing that justified it — which is the
 * difference between a guest and an account.
 *
 * @property int $id
 * @property int $gathering_id
 * @property int $delegation_id
 * @property string $seat
 * @property Carbon|null $left_at
 */
class Seat extends Model
{
    protected $table = 'streetmesh_gathering_seats';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['left_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Gathering, $this>
     */
    public function gathering(): BelongsTo
    {
        return $this->belongsTo(Gathering::class);
    }

    /**
     * @return BelongsTo<Delegation, $this>
     */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(Delegation::class);
    }
}
