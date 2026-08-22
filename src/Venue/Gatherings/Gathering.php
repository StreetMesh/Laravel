<?php

namespace StreetMesh\Server\Venue\Gatherings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something happening at this venue, as the venue remembers it.
 *
 * The durable half of a room. The hub holds the live version — the board, the
 * clock, whose turn it is — and forgets all of it when the last person leaves.
 * This is what is still here afterwards, and what a record gets signed about.
 *
 * @property int $id
 * @property string $experience
 * @property string $key
 * @property string $status
 * @property Carbon|null $concluded_at
 * @property array<string, mixed>|null $outcome
 */
class Gathering extends Model
{
    public const OPEN = 'open';

    public const CONCLUDED = 'concluded';

    protected $table = 'streetmesh_gatherings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'concluded_at' => 'datetime',
            'outcome' => 'array',
        ];
    }

    /**
     * Found by the name in a link, however that link came back.
     *
     * A key is a ULID, and a ULID is Crockford base32 — case-insensitive by
     * specification, written in upper case by convention. An invitation goes
     * out into the world and returns through a message, a mail client, a
     * paste, and any of those may lower-case it or leave a space on the end.
     * Every one of them is still the same table.
     *
     * Matching the stored text exactly meant a link that had travelled at all
     * arrived as "There is no game here" — which reads as the game being over
     * rather than as the address having been tidied up on the way.
     *
     * @param  Builder<Gathering>  $query
     * @return Builder<Gathering>
     */
    public function scopeKeyed($query, string $key)
    {
        return $query->where('key', strtoupper(trim($key)));
    }

    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    /**
     * What the hub knows this room by, and what a ticket is minted against.
     *
     * Experience and key together, because the experience alone names a *kind*
     * of thing and two chess games are not the same room.
     */
    public function room(): string
    {
        return $this->experience.'/'.$this->key;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
