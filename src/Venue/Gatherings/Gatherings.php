<?php

namespace StreetMesh\Server\Venue\Gatherings;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Protocol\Permissions\Tickets;
use StreetMesh\Server\Venue\Experiences\Audience;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Parties\Parties;

/**
 * Opening something, seating people at it, and letting them in.
 *
 * This is where the venue exercises the one authority the hub does not have:
 * deciding who may be somewhere. The hub can check that a venue said so; only
 * the venue can decide it in the first place, and only here is that decision
 * durable enough to survive the hub restarting.
 *
 * An experience puts its rules on top. What is here is true of chess, a watch
 * party and an auction alike: something is open, people are in it, and each of
 * them can be handed a way in.
 */
final class Gatherings
{
    public function __construct(
        private readonly Tickets $tickets,
        private readonly Experiences $experiences,
        private readonly Parties $parties,
    ) {}

    public function open(string $experience): Gathering
    {
        return Gathering::create([
            'experience' => $experience,
            'key' => (string) Str::ulid(),
            'status' => Gathering::OPEN,
        ]);
    }

    /**
     * Put somebody in it, or find them already there.
     *
     * Idempotent, because arriving twice is what a browser does — a reload, a
     * reconnection, a second tab — and none of those should be an error or a
     * second seat.
     */
    public function seat(Gathering $gathering, Delegation $visitor, string $seat = ''): Seat
    {
        if (! $gathering->isOpen()) {
            throw new RuntimeException('That is over.');
        }

        $existing = $this->seatOf($gathering, $visitor);

        if ($existing !== null) {
            /*
             * Point the chair at the permission we can actually use. Coming
             * back through the door mints a fresh delegation, and the one they
             * sat down with may since have expired — settling against it would
             * fail at the last step, after the game was already over.
             */
            if ($existing->delegation_id !== $visitor->id) {
                $existing->update(['delegation_id' => $visitor->id]);
            }

            return $existing;
        }

        /*
         * Two people cannot both be white. Enforced by asking the database
         * rather than by looking first, because looking first leaves a gap
         * between the check and the write that two simultaneous arrivals fit
         * through exactly.
         */
        if ($seat !== '' && $this->taken($gathering, $seat)) {
            throw new RuntimeException("Somebody is already in the [{$seat}] seat.");
        }

        try {
            return Seat::create([
                'gathering_id' => $gathering->id,
                'delegation_id' => $visitor->id,
                'seat' => $seat,
            ]);
        } catch (QueryException) {
            throw new RuntimeException('That seat was taken a moment ago.');
        }
    }

    /**
     * A way in, for somebody with a place here — or somebody just looking.
     *
     * The ticket is minted from the seat rather than from the request, so what
     * it says is what this venue decided rather than what a browser asked for.
     *
     * Nobody is null: a passer-by who has never been here, following a link to
     * a game. Whether that is somebody to let in is the experience's answer
     * rather than this one's, and it is a question about privacy — so it is
     * asked, per gathering, rather than assumed either way.
     */
    public function admit(Gathering $gathering, ?Delegation $visitor): string
    {
        if (! $gathering->isOpen()) {
            throw new RuntimeException('That is over.');
        }

        $seat = $visitor === null ? null : $this->seatOf($gathering, $visitor);
        $seated = $seat !== null && $seat->left_at === null;

        /*
         * Who they are here with, which this gathering has no opinion about.
         *
         * A party spans the venue and no experience is asked whether it minds,
         * so this is read rather than decided — and it rides the ticket because
         * the room has to be able to show that somebody is in one.
         */
        $party = $this->parties->partyOf($visitor)?->room() ?? '';

        if ($seated) {
            return $this->tickets->mint($visitor, $gathering->room(), $seat->seat, $this->filled($gathering), $party);
        }

        if (! $this->audienceFor($gathering)->admits(arrived: $visitor !== null, seated: false)) {
            throw new RuntimeException('That visitor has no place there.');
        }

        /*
         * A watcher, in no seat.
         *
         * Named when the venue knows who they are and anonymous when it does
         * not, because a stranger watching a public game has told this server
         * nothing about themselves and inventing an identity for them would be
         * the opposite of what the setting they are relying on says.
         */
        return $visitor === null
            ? $this->tickets->watcher($gathering->room(), $this->filled($gathering))
            : $this->tickets->mint($visitor, $gathering->room(), '', $this->filled($gathering), $party);
    }

    /**
     * Who this gathering is open to, as its own experience sees it.
     *
     * An experience nothing installed no longer answers for — the package was
     * removed, or was never here — and there is nobody left to ask whether its
     * gatherings are private. The only safe reading of silence is the closed
     * one: the people already seated, and nobody else.
     */
    private function audienceFor(Gathering $gathering): Audience
    {
        return $this->experiences->get($gathering->experience)?->audience($gathering)
            ?? Audience::Players;
    }

    /**
     * Which seats somebody is sitting in, whether or not they are looking.
     *
     * A seat outlives a connection on purpose — that is what lets somebody come
     * back to their own game — and this is the answer the realtime half cannot
     * work out for itself, because all it can see is who is online right now.
     *
     * @return array<int, string>
     */
    private function filled(Gathering $gathering): array
    {
        return Seat::query()
            ->where('gathering_id', $gathering->id)
            ->whereNull('left_at')
            ->pluck('seat')
            ->all();
    }

    /**
     * Where somebody is sitting, if they are.
     *
     * Found by *who they are* rather than by which permission they are holding.
     * A delegation is one trip through the door: come back tomorrow, or in
     * another browser, and the same person is carrying a different one. Keyed
     * on the delegation, this venue sat one person down twice — a game showed
     * "2 at the table" with nobody else in the building, and the second chair
     * was the same human returning.
     */
    public function seatOf(Gathering $gathering, Delegation $visitor): ?Seat
    {
        return Seat::query()
            ->where('gathering_id', $gathering->id)
            ->whereHas('delegation', fn ($holder) => $holder->where('did', $visitor->did))
            ->first();
    }

    /**
     * Tables somebody opened and nobody came to.
     *
     * One seat, because two is a game — whether or not a move was made, two
     * people met, and that is not this. Still open, because a concluded
     * gathering is a record rather than an invitation.
     *
     * @return Collection<int, Gathering>
     */
    public function waiting(int $minutes): Collection
    {
        return Gathering::query()
            ->where('status', Gathering::OPEN)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->has('seats', '<', 2)
            ->get();
    }

    /**
     * Over, and what happened.
     *
     * The outcome is the experience's to describe — this holds no opinion about
     * what a result looks like — but it is kept here, because the hub does not
     * keep anything. A room is memory and is gone when the last person leaves,
     * so a venue that recorded only "concluded" could never show a finished
     * gathering to anybody who came back to look at it.
     *
     * @param  array<string, mixed>  $outcome
     */
    public function conclude(Gathering $gathering, array $outcome = []): Gathering
    {
        $gathering->update([
            'status' => Gathering::CONCLUDED,
            'concluded_at' => now(),
            'outcome' => $outcome === [] ? null : $outcome,
        ]);

        return $gathering->refresh();
    }

    private function taken(Gathering $gathering, string $seat): bool
    {
        return Seat::query()
            ->where('gathering_id', $gathering->id)
            ->where('seat', $seat)
            ->whereNull('left_at')
            ->exists();
    }
}
