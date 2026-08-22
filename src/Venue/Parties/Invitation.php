<?php

namespace StreetMesh\Server\Venue\Parties;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Being asked to join a party, and not yet having answered.
 *
 * A party is invite-only, which makes this the whole of the way in. There is no
 * open door, no code to type and nothing to browse: somebody already inside
 * points at a name they can see in the room they are in, and that person says
 * yes or no.
 *
 * Addressed to a DID rather than to a delegation on purpose. An invitation is
 * offered and answered minutes apart, and a reload in between mints the invitee
 * a fresh permission — an invitation pinned to the old one would quietly
 * address nobody, which is the same bug seats were keyed into once already.
 *
 * @property int $id
 * @property int $party_id
 * @property string $did
 * @property string $invited_by_did
 * @property string $invited_by_name
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 */
class Invitation extends Model
{
    /**
     * How long an unanswered invitation stands.
     *
     * Long enough to notice one and think about it, short enough that a roster
     * is not carrying knocks from people who have gone home. An invitation that
     * never expired would make "you have an invitation" a permanent feature of
     * somebody's screen rather than a thing happening now.
     */
    public const LIFETIME_MINUTES = 10;

    protected $table = 'streetmesh_party_invitations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * Still worth showing somebody.
     *
     * Answered or stale are the same thing from a screen's point of view —
     * there is nothing left to press — so they are one question rather than
     * two that every caller would have to remember to ask together.
     */
    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->declined_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now());
    }
}
