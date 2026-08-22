<?php

namespace StreetMesh\Server\Venue\Parties;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use StreetMesh\Server\Protocol\Permissions\Delegation;

/**
 * Somebody's place in a party.
 *
 * Held against a delegation rather than a person, for the same reason a seat
 * is: a visitor is only ever here on borrowed authority, and when that is
 * withdrawn their place in the party goes with it. Somebody who leaves the
 * venue leaves the party, and nothing has to notice separately.
 *
 * Where they are right now is deliberately not here. That changes every time
 * somebody walks through a door and is worth nothing a second later, so it
 * lives in the party's room with the rest of the hot-path state — this is only
 * the record that they are in the party at all.
 *
 * @property int $id
 * @property int $party_id
 * @property int $delegation_id
 * @property Carbon|null $left_at
 */
class Member extends Model
{
    protected $table = 'streetmesh_party_members';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['left_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<Delegation, $this>
     */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(Delegation::class);
    }
}
