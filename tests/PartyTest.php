<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Gatherings\Gatherings;
use StreetMesh\Server\Venue\Parties\Invitation;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;

/**
 * People who came here together, staying together.
 *
 * A party is the venue's answer to "who am I here with", and it is deliberately
 * the venue's rather than any experience's: an operator turns it on for their
 * whole server and every experience installed is in it whether or not its author
 * ever thought about parties.
 *
 * What is being protected here is mostly the invariant that somebody is in at
 * most one. Two would mean two voice channels, which is the arrangement the
 * superseding rule exists to make impossible.
 */
class PartyTest extends TestCase
{
    private const CHESS = 'com.streetmesh.games.chess';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Off by default everywhere, so the tests that want one say so.
        $app['config']->set('streetmesh.venue.parties.enabled', true);
        $app['config']->set('streetmesh.venue.parties.size', 4);
    }

    private function parties(): Parties
    {
        return $this->app->make(Parties::class);
    }

    private function visitor(string $who = 'alice'): Delegation
    {
        return Delegation::create([
            'did' => 'did:web:'.$who.'.home.test',
            'handle' => $who.'.home.test',
            'issuer' => 'https://home.test',
            'dpop_key' => Delegation::store(P256::generate()),
            'access_token' => 'a-live-token',
            'scope' => 'atproto',
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /** Somebody in a party, so a test can get on with what it is about. */
    private function partyOf(Delegation $founder, Delegation ...$others): Party
    {
        $party = $this->parties()->open($founder);

        foreach ($others as $other) {
            $invitation = $this->parties()->invite($party, $founder, (string) $other->did);
            $this->parties()->accept($invitation, $other);
        }

        return $party;
    }

    /**
     * A venue that does not do parties has none, rather than empty ones.
     *
     * The operator's switch is the only thing consulted anywhere. No experience
     * is asked, which is the whole point of putting it here — an experience
     * author cannot know which venue their package lands in, and the operator
     * can read their own configuration.
     */
    public function test_a_venue_with_parties_off_will_not_start_one(): void
    {
        config(['streetmesh.venue.parties.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This venue does not do parties');

        $this->parties()->open($this->visitor());
    }

    public function test_starting_one_puts_you_in_it(): void
    {
        $alice = $this->visitor();

        $party = $this->parties()->open($alice);

        $this->assertTrue($party->isOpen());
        $this->assertNotNull($this->parties()->memberOf($party, $alice));
        $this->assertSame($party->id, $this->parties()->partyOf($alice)?->id);
    }

    /**
     * The only way in.
     *
     * There is no open door and nothing to browse. Somebody already inside
     * points at a name they can see, and that person answers.
     */
    public function test_somebody_outside_a_party_cannot_ask_anybody_into_it(): void
    {
        $party = $this->parties()->open($this->visitor());
        $mallory = $this->visitor('mallory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only somebody in a party');

        $this->parties()->invite($party, $mallory, 'did:web:bob.home.test');
    }

    public function test_being_asked_and_saying_yes_puts_you_in(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');
        $party = $this->parties()->open($alice);

        $invitation = $this->parties()->invite($party, $alice, (string) $bob->did);
        $this->parties()->accept($invitation, $bob);

        $this->assertSame(2, $this->parties()->present($party)->count());
        $this->assertSame($party->id, $this->parties()->partyOf($bob)?->id);
    }

    /**
     * Any member may ask, not only whoever opened it.
     *
     * A party where one person is the doorman stops being a group of people and
     * becomes somebody's guest list. The cost is that a party is transitively
     * open to anybody any member trusts, which is the ordinary bargain.
     */
    public function test_anybody_already_in_can_ask_the_next_person(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');
        $carol = $this->visitor('carol');

        $party = $this->partyOf($alice, $bob);

        $invitation = $this->parties()->invite($party, $bob, (string) $carol->did);
        $this->parties()->accept($invitation, $carol);

        $this->assertSame(3, $this->parties()->present($party)->count());
    }

    /**
     * An invitation is addressed to somebody, and being able to see one is not
     * the same as it being yours.
     */
    public function test_an_invitation_is_no_use_to_anybody_else(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');
        $mallory = $this->visitor('mallory');

        $party = $this->parties()->open($alice);
        $invitation = $this->parties()->invite($party, $alice, (string) $bob->did);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not addressed to you');

        $this->parties()->accept($invitation, $mallory);
    }

    public function test_an_invitation_nobody_answered_goes_stale(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->parties()->open($alice);
        $invitation = $this->parties()->invite($party, $alice, (string) $bob->did);

        $this->travel(Invitation::LIFETIME_MINUTES + 1)->minutes();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer open');

        $this->parties()->accept($invitation->refresh(), $bob);
    }

    /**
     * One party at a time, and this is the invariant the feature rests on.
     *
     * Being in two would put somebody in two voice channels at once — which is
     * exactly the hidden side channel that superseding exists to prevent. You
     * cannot listen to two conversations, and a venue that let somebody try
     * would be handing out the thing this design refuses everywhere else.
     */
    public function test_nobody_is_in_two_parties(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');
        $carol = $this->visitor('carol');

        $this->partyOf($alice, $bob);

        $second = $this->parties()->open($carol);
        $invitation = $this->parties()->invite($second, $carol, (string) $bob->did);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already in a party');

        $this->parties()->accept($invitation, $bob);
    }

    /**
     * Refused rather than degraded.
     *
     * Media between people in a party is peer-to-peer: everybody uploads a copy
     * of their stream to everybody else, and it stops working somewhere past
     * four. A mesh that sags with each arrival is the failure nobody can
     * diagnose, because everything keeps appearing to work.
     */
    public function test_a_party_fills_up(): void
    {
        config(['streetmesh.venue.parties.size' => 2]);

        $alice = $this->visitor();
        $bob = $this->visitor('bob');
        $carol = $this->visitor('carol');

        $party = $this->partyOf($alice, $bob);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('full');

        $this->parties()->invite($party, $alice, (string) $carol->did);
    }

    /**
     * However large an operator asks for, the media decides.
     *
     * Clamped rather than obeyed, and said out loud at boot by `Readiness` —
     * a venue that quietly did something other than its configuration says is
     * the failure this codebase keeps refusing to ship.
     */
    public function test_a_party_is_never_larger_than_the_media_can_carry(): void
    {
        config(['streetmesh.venue.parties.size' => 12]);

        $this->assertSame(Parties::MESH_CEILING, $this->parties()->size());
    }

    /**
     * A delegation is one trip through the door, not a person.
     *
     * The same lesson seats paid for: keyed on the permission, one human coming
     * back from a reload becomes a second member and a party of two reports
     * three people in it.
     */
    public function test_somebody_coming_back_is_still_in_the_party(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->partyOf($alice, $bob);

        // The same person, a second time through the door.
        $again = $this->visitor('bob');

        $this->assertNotSame($bob->id, $again->id);
        $this->assertSame($bob->did, $again->did);

        $this->assertSame($party->id, $this->parties()->partyOf($again)?->id);
        $this->assertSame(2, $this->parties()->present($party)->count());
    }

    /**
     * An invitation outlives a reload, because it names a person.
     *
     * Pinned to a delegation it would quietly address a trip through the door
     * that is over — the invitee reloads while deciding, and the offer they can
     * see is one they can no longer accept.
     */
    public function test_an_invitation_survives_the_invitee_reloading(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->parties()->open($alice);
        $invitation = $this->parties()->invite($party, $alice, (string) $bob->did);

        $again = $this->visitor('bob');

        $this->parties()->accept($invitation, $again);

        $this->assertSame($party->id, $this->parties()->partyOf($again)?->id);
    }

    /**
     * Leaving is not a one-way door.
     *
     * A place is kept rather than deleted — that is what `left_at` is for — and
     * the table allows one row per person per party. Looking only at who is
     * currently in it found nothing for somebody coming back and tried to write
     * a second row, which the database refused: leaving a party you could see
     * on screen meant never getting back into it, and the refusal named a
     * constraint rather than saying so.
     */
    public function test_somebody_who_left_can_come_back(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->partyOf($alice, $bob);

        $this->parties()->leave($party, $bob);

        $left = $this->parties()->partyOf($bob);
        $this->assertNull($left);

        /* The same trip through the door, which is the case that failed. */
        $this->parties()->joinByCode((string) $party->code, $bob);

        $rejoined = $this->parties()->partyOf($bob);
        $this->assertNotNull($rejoined, 'coming back should seat them again');
        $this->assertSame($party->id, $rejoined->id);
        $this->assertSame(2, $this->parties()->present($party)->count());
    }

    /**
     * And can come back tomorrow, holding a different permission.
     */
    public function test_somebody_who_left_can_come_back_under_a_new_permission(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->partyOf($alice, $bob);

        $this->parties()->leave($party, $bob);

        $again = $this->visitor('bob');

        $this->parties()->joinByCode((string) $party->code, $again);

        $this->assertSame($party->id, $this->parties()->partyOf($again)?->id);
        $this->assertSame(2, $this->parties()->present($party)->count());
    }

    public function test_the_last_person_out_breaks_it_up(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->partyOf($alice, $bob);

        $this->parties()->leave($party, $alice);
        $this->assertTrue($party->refresh()->isOpen(), 'one person is still a party');

        $this->parties()->leave($party, $bob);
        $this->assertFalse($party->refresh()->isOpen());
    }

    /**
     * A party can empty without anybody having left.
     *
     * Membership is held against the permission it was joined under, so a
     * visitor giving theirs back takes their membership with it and nothing
     * notices at the time. What is left standing is a name an old invitation
     * could still be accepted against.
     */
    public function test_a_party_whose_members_all_went_home_is_broken_up(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->partyOf($alice, $bob);

        $alice->delete();
        $bob->delete();

        $this->assertTrue($party->refresh()->isOpen(), 'nothing noticed at the time');
        $this->assertTrue($this->parties()->deserted()->contains('id', $party->id));

        $this->artisan('parties:tidy')->assertSuccessful();

        $this->assertFalse($party->refresh()->isOpen());
    }

    /**
     * The ticket for a table says who its holder is here with.
     *
     * Carried so the table can *show* it. Somebody in a party has their voice
     * superseded by the party's, which leaves them present at the table and
     * unhearable there — and a person who cannot be told that is a person
     * talking to a wall without knowing why.
     */
    public function test_a_ticket_says_which_party_its_holder_is_in(): void
    {
        $alice = $this->visitor();
        $party = $this->parties()->open($alice);

        $gatherings = $this->app->make(Gatherings::class);
        $gathering = $gatherings->open(self::CHESS);
        $gatherings->seat($gathering, $alice, 'white');

        $claims = $this->claimsOf($gatherings->admit($gathering, $alice));

        $this->assertSame($party->room(), $claims['party']);
    }

    public function test_a_ticket_for_somebody_on_their_own_says_so(): void
    {
        $alice = $this->visitor();

        $gatherings = $this->app->make(Gatherings::class);
        $gathering = $gatherings->open(self::CHESS);
        $gatherings->seat($gathering, $alice, 'white');

        $claims = $this->claimsOf($gatherings->admit($gathering, $alice));

        $this->assertSame('', $claims['party']);
    }

    /**
     * A party will say whether somebody belongs at it, and nothing else.
     *
     * There is no ticket any more, because there is no room to be let into: a
     * party is a space where notes are left and a conversation is kept, not
     * something anybody joins. What survives is the refusal, which is the only
     * place a party can say no in words a person reads.
     */
    public function test_a_member_is_vouched_for(): void
    {
        $alice = $this->visitor();
        $party = $this->parties()->open($alice);

        $this->expectNotToPerformAssertions();

        /* Saying nothing is how this says yes. */
        $this->parties()->vouchFor($party, $alice);
    }

    public function test_somebody_outside_a_party_is_not_vouched_for(): void
    {
        $party = $this->parties()->open($this->visitor());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in that party');

        $this->parties()->vouchFor($party, $this->visitor('mallory'));
    }

    /**
     * What a ticket actually says, read the way the hub reads it.
     *
     * @return array<string, mixed>
     */
    private function claimsOf(string $ticket): array
    {
        $payload = explode('.', $ticket)[1] ?? '';

        /** @var array<string, mixed> $claims */
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return $claims;
    }
}
