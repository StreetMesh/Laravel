<?php

namespace StreetMesh\Server\Venue\Parties;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * People who are here together, wherever each of them happens to be.
 *
 * The counterpart to a gathering, and the differences are the whole idea. A
 * gathering is one thing happening and everybody in it is there for that; a
 * party is a few people who arrived together and stay in earshot while they
 * wander off into different experiences.
 *
 * It belongs to the venue rather than to any experience in it. An operator
 * turns parties on for their server, and from then on a party spans everything
 * installed — which is why nothing here mentions an experience and why no
 * experience is asked whether it approves.
 *
 * @property int $id
 * @property string $key
 * @property string|null $code
 * @property Carbon|null $disbanded_at
 */
class Party extends Model
{
    /**
     * The room type a party is served by, which is the hub's own rather than
     * any experience's.
     *
     * Reverse-domain like every other room name, and reserved here so that an
     * experience cannot ship a room claiming to be the party.
     */
    public const ROOM = 'com.streetmesh.party';

    protected $table = 'streetmesh_parties';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'disbanded_at' => 'datetime',
        ];
    }

    /**
     * Found by the name in an invitation, however that invitation travelled.
     *
     * The same tidying a gathering key gets, for the same reason: a ULID is
     * case-insensitive by specification and everything that carries text
     * around feels free to act on it.
     *
     * @param  Builder<Party>  $query
     * @return Builder<Party>
     */
    public function scopeKeyed($query, string $key)
    {
        return $query->where('key', strtoupper(trim($key)));
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * What the hub knows this party's room by.
     *
     * The same shape a gathering's room name has — a kind of thing and then
     * which one of them — so that a ticket is checked the same way whichever
     * of the two it was minted for.
     */
    public function room(): string
    {
        return self::ROOM.'/'.$this->key;
    }

    public function isOpen(): bool
    {
        return $this->disbanded_at === null;
    }
}
