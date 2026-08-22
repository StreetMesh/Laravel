<?php

namespace StreetMesh\Server\Venue\Parties;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use StreetMesh\Server\Protocol\Permissions\Delegation;

/**
 * Starting a party, asking somebody into it, and keeping it the size it can be.
 *
 * The venue half of the feature. Everything that decides *who is together* is
 * here, and it is durable — the hub holds who is connected this second and
 * forgets it, which is no use for answering whether somebody who reloaded is
 * still in the party.
 *
 * Nothing here asks an experience anything. Parties are on or off for the whole
 * venue, by the operator's hand, and the reason is that the operator is the only
 * party who knows what is installed. An experience author cannot know which
 * venue their package lands in; an operator can read their own configuration.
 *
 * The cost of that is real and is the operator's to carry: a venue with parties
 * on and a competitive experience installed has given its players a private
 * voice channel their opponents cannot hear. `Readiness` says so out loud at
 * boot, and then it is their call.
 */
final class Parties
{
    /**
     * How many people can be in one party before the media stops working.
     *
     * Peer-to-peer means every participant uploads a copy of their stream to
     * every other, which is ideal for two and hopeless somewhere past four.
     * There is no relay driver yet, so this is a fact rather than a setting —
     * when one arrives this becomes a question about which driver is on, and
     * the ceiling lifts for the venues paying for it.
     *
     * Refused rather than degraded, deliberately. A mesh that sags with each
     * arrival is the failure nobody can diagnose: everybody's video gets worse
     * and nothing anywhere reports a problem.
     */
    public const MESH_CEILING = 4;

    /** Whether this venue offers parties at all. */
    public function enabled(): bool
    {
        return (bool) config('streetmesh.venue.parties.enabled', false);
    }

    /**
     * The most people this venue will put in one party.
     *
     * Clamped to what the media can actually carry rather than trusted. An
     * operator who set this to twelve has misunderstood something, and the
     * useful response is a party of four rather than twelve people whose
     * cameras all fail — `Readiness` has already told them so at boot.
     */
    public function size(): int
    {
        $configured = (int) config('streetmesh.venue.parties.size', self::MESH_CEILING);

        return max(2, min($configured, self::MESH_CEILING));
    }

    /**
     * Start one, with the person starting it already in it.
     *
     * A party of one is an ordinary state and not a bug: somebody opens one and
     * then looks around for who to ask. It disbands on its own if they wander
     * off without inviting anybody.
     */
    public function open(Delegation $founder): Party
    {
        $this->refuseUnlessOffered();

        if ($this->partyOf($founder) !== null) {
            throw new RuntimeException('You are already in a party.');
        }

        $party = Party::create([
            'key' => (string) Str::ulid(),
            'code' => $this->freshCode(),
        ]);

        Member::create([
            'party_id' => $party->id,
            'delegation_id' => $founder->id,
        ]);

        return $party;
    }

    /**
     * Ask somebody in.
     *
     * The only way into a party. Whoever is asking has to be in it already —
     * any member may invite, rather than only whoever opened it, because a
     * party where one person is the doorman stops being a group of people and
     * starts being somebody's guest list.
     *
     * That does mean a party is transitively open to anybody any member
     * trusts, which is the ordinary bargain of being in one.
     */
    public function invite(Party $party, Delegation $host, string $did, string $name = ''): Invitation
    {
        $this->refuseUnlessOffered();

        if (! $party->isOpen()) {
            throw new RuntimeException('That party has broken up.');
        }

        if ($this->memberOf($party, $host) === null) {
            throw new RuntimeException('Only somebody in a party can ask anybody into it.');
        }

        if ($did === '' || $did === $host->did) {
            throw new RuntimeException('There is nobody there to ask.');
        }

        if ($this->present($party)->count() >= $this->size()) {
            throw new RuntimeException('That party is full.');
        }

        /*
         * Asking again refreshes the same invitation rather than making a
         * second one. Somebody who did not notice the first knock is somebody
         * to knock for again, and a roster showing the same offer twice is a
         * venue that looks broken.
         */
        return Invitation::updateOrCreate(
            ['party_id' => $party->id, 'did' => $did],
            [
                'invited_by_did' => (string) $host->did,
                'invited_by_name' => $name === '' ? (string) $host->handle : $name,
                'expires_at' => now()->addMinutes(Invitation::LIFETIME_MINUTES),
                'accepted_at' => null,
                'declined_at' => null,
            ],
        );
    }

    /**
     * Say yes to one.
     *
     * Checked against who is answering rather than trusting the invitation to
     * name them: an invitation is a row anybody could point at, and the only
     * thing that makes it theirs is that it was addressed to them.
     */
    public function accept(Invitation $invitation, Delegation $visitor): Member
    {
        $this->refuseUnlessOffered();

        if (! $invitation->isOpen()) {
            throw new RuntimeException('That invitation is no longer open.');
        }

        if ($invitation->did !== $visitor->did) {
            throw new RuntimeException('That invitation was not addressed to you.');
        }

        $party = $invitation->party;

        if (! $party->isOpen()) {
            throw new RuntimeException('That party has broken up.');
        }

        /*
         * One party at a time, and this is the invariant the whole feature
         * rests on. Being in two would put somebody in two voice channels at
         * once, which is the arrangement the superseding rule exists to make
         * impossible — you cannot listen to two conversations, and a venue
         * that let you try would be handing out exactly the hidden side channel
         * this design refuses everywhere else.
         */
        if ($this->partyOf($visitor) !== null) {
            throw new RuntimeException('You are already in a party. Leave that one first.');
        }

        if ($this->present($party)->count() >= $this->size()) {
            throw new RuntimeException('That party filled up.');
        }

        $invitation->update(['accepted_at' => now()]);

        return $this->join($party, $visitor);
    }

    /**
     * Get in by saying the word.
     *
     * The looser of the two ways in, and it is worth being honest about what it
     * gives up: an invitation is an act between two people who are both here,
     * and a code is a thing that can be forwarded. Whoever holds it can join,
     * and the party cannot tell how they came by it.
     *
     * What keeps that acceptable is how small the blast radius is. A code dies
     * with its party, admits nobody once the party is full, and anybody already
     * inside can both see who arrived and rotate it.
     */
    public function joinByCode(string $code, Delegation $visitor): Member
    {
        $this->refuseUnlessOffered();

        $party = Party::query()
            ->where('code', $this->tidyCode($code))
            ->whereNull('disbanded_at')
            ->first();

        if ($party === null) {
            throw new RuntimeException('No party here answers to that.');
        }

        $already = $this->memberOf($party, $visitor);

        if ($already !== null) {
            return $already;
        }

        if ($this->partyOf($visitor) !== null) {
            throw new RuntimeException('You are already in a party. Leave that one first.');
        }

        if ($this->present($party)->count() >= $this->size()) {
            throw new RuntimeException('That party is full.');
        }

        return $this->join($party, $visitor);
    }

    /**
     * A new word for the same party.
     *
     * The answer to a code having travelled further than anybody meant. Any
     * member may do it, because any member can see who has turned up.
     */
    public function rotateCode(Party $party, Delegation $member): string
    {
        if ($this->memberOf($party, $member) === null) {
            throw new RuntimeException('You are not in that party.');
        }

        $party->update(['code' => $this->freshCode()]);

        return (string) $party->code;
    }

    /**
     * Something a person can say across a table without spelling it.
     *
     * No `0`/`O`, no `1`/`I`/`L`, no vowels — so it cannot be misheard for
     * another code and cannot accidentally spell anything.
     */
    private function freshCode(): string
    {
        $alphabet = 'BCDFGHJKMNPQRSTVWXYZ23456789';

        do {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Party::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * However it was typed, said or pasted.
     *
     * A code goes out into the world through a message, a whiteboard and
     * somebody's voice, and comes back lower-cased, spaced or with the hyphen
     * a person added to make it readable.
     */
    private function tidyCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    public function decline(Invitation $invitation, Delegation $visitor): void
    {
        if ($invitation->did !== $visitor->did) {
            throw new RuntimeException('That invitation was not addressed to you.');
        }

        $invitation->update(['declined_at' => now()]);
    }

    /**
     * Put somebody in, or find them already there.
     *
     * Idempotent for the same reason seating is: a reload, a second tab and a
     * reconnection all arrive as somebody joining again, and none of them is an
     * error or a second place at the table.
     */
    private function join(Party $party, Delegation $visitor): Member
    {
        /*
         * Anybody who has ever been in this party, including somebody who left.
         *
         * A place is kept rather than deleted — that is what `left_at` is for —
         * and the table allows one row per person per party. So looking only at
         * who is *currently* in it found nothing for a returning member and
         * tried to write a second row, which the database refused. Leaving a
         * party was a one-way door, and the refusal named a constraint rather
         * than saying so.
         */
        $existing = Member::query()
            ->where('party_id', $party->id)
            ->whereHas('delegation', fn ($holder) => $holder->where('did', $visitor->did))
            ->first();

        if ($existing !== null) {
            /*
             * Back in, under whichever permission they are holding now. Coming
             * through the door again mints a fresh delegation, and the one they
             * first joined under may since have expired.
             */
            $existing->update(['delegation_id' => $visitor->id, 'left_at' => null]);

            return $existing;
        }

        return Member::create([
            'party_id' => $party->id,
            'delegation_id' => $visitor->id,
        ]);
    }

    /**
     * Walk out of it.
     *
     * A party with nobody left in it is disbanded rather than left standing.
     * An empty party is a name an old invitation could still be accepted
     * against, which would put somebody alone in a room they were asked into
     * by people who have gone.
     */
    public function leave(Party $party, Delegation $visitor): void
    {
        $member = $this->memberOf($party, $visitor);

        if ($member === null) {
            return;
        }

        $member->update(['left_at' => now()]);

        if ($this->present($party)->isEmpty()) {
            $this->disband($party);
        }
    }

    public function disband(Party $party): void
    {
        $party->update(['disbanded_at' => now()]);
    }

    /**
     * Confirm somebody may be at this party, and hand back nothing.
     *
     * There is no ticket any more: a party is not a room anybody joins, so
     * nothing needs a signed way in. What is left is the refusal — the two
     * sentences a person may need to read — and the round trip that carries
     * them. The poll that follows says nothing anybody can act on when it
     * fails, so this is the only place a party can say no out loud.
     */
    public function vouchFor(Party $party, Delegation $visitor): void
    {
        $this->refuseUnlessOffered();

        if (! $party->isOpen()) {
            throw new RuntimeException('That party has broken up.');
        }

        if ($this->memberOf($party, $visitor) === null) {
            throw new RuntimeException('You are not in that party.');
        }
    }

    /**
     * Who is still in it.
     *
     * @return Collection<int, Member>
     */
    public function present(Party $party): Collection
    {
        return Member::query()
            ->where('party_id', $party->id)
            ->whereNull('left_at')
            ->get();
    }

    /**
     * Somebody's place in this party, if they have one.
     *
     * Found by *who they are* rather than by which permission they are holding,
     * which is the lesson seats paid for: a delegation is one trip through the
     * door, and the same human coming back tomorrow carries a different one.
     */
    public function memberOf(Party $party, Delegation $visitor): ?Member
    {
        return Member::query()
            ->where('party_id', $party->id)
            ->whereNull('left_at')
            ->whereHas('delegation', fn ($holder) => $holder->where('did', $visitor->did))
            ->first();
    }

    /**
     * Which party somebody is in, if any.
     *
     * At most one, always. See `accept` for why that is the invariant rather
     * than a convenience.
     */
    public function partyOf(?Delegation $visitor): ?Party
    {
        if ($visitor === null || $visitor->did === null || ! $this->enabled()) {
            return null;
        }

        return Member::query()
            ->whereNull('left_at')
            ->whereHas('delegation', fn ($holder) => $holder->where('did', $visitor->did))
            ->whereHas('party', fn ($party) => $party->whereNull('disbanded_at'))
            ->first()?->party;
    }

    /**
     * Invitations somebody has not answered yet.
     *
     * @return Collection<int, Invitation>
     */
    public function invitationsFor(?Delegation $visitor): Collection
    {
        if ($visitor === null || $visitor->did === null || ! $this->enabled()) {
            return new Collection;
        }

        return Invitation::query()
            ->open()
            ->where('did', $visitor->did)
            ->whereHas('party', fn ($party) => $party->whereNull('disbanded_at'))
            ->get();
    }

    /**
     * Who is at this venue and could be asked into a party.
     *
     * The answer to "point at a name you can see", and it is deliberately the
     * people who are *here* rather than anybody the asker knows. A venue holds
     * no social graph and should not grow one: an invitation is an act between
     * two people who are both in the building, not a query against a list of
     * friends kept somewhere.
     *
     * One row per person, not per delegation. The same human with a laptop and
     * a phone is one name on this list, and showing them twice would be an
     * invitation that behaves oddly depending on which copy was pressed.
     *
     * Already in a party means not on the list. They would be refused anyway,
     * and offering somebody an action that cannot succeed is worse than not
     * offering it.
     *
     * @return Collection<int, Delegation>
     */
    public function here(Delegation $asking): Collection
    {
        $spokenFor = Member::query()
            ->whereNull('left_at')
            ->whereHas('party', fn ($party) => $party->whereNull('disbanded_at'))
            ->with('delegation')
            ->get()
            ->pluck('delegation.did')
            ->filter()
            ->all();

        return Delegation::query()
            ->whereNotNull('did')
            ->where('did', '!=', (string) $asking->did)
            ->whereNotIn('did', $spokenFor)
            ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('handle')
            ->get()
            ->unique('did')
            ->values();
    }

    /**
     * Who is in a party, by the names the venue vouches for.
     *
     * @return \Illuminate\Support\Collection<int, Delegation>
     */
    public function rosterOf(Party $party): \Illuminate\Support\Collection
    {
        return $this->present($party)
            ->load('delegation')
            ->pluck('delegation')
            ->filter()
            ->unique('did')
            ->values();
    }

    /**
     * Parties nobody is in any more.
     *
     * Membership goes with the delegation it is held against, so a party can
     * empty without anybody pressing anything — everybody's permission expired
     * and the rows went with it. Nothing notices at the time, which is what
     * this is for.
     *
     * @return Collection<int, Party>
     */
    public function deserted(): Collection
    {
        return Party::query()
            ->whereNull('disbanded_at')
            ->whereDoesntHave('members', fn ($member) => $member->whereNull('left_at'))
            ->get();
    }

    private function refuseUnlessOffered(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('This venue does not do parties.');
        }
    }
}
