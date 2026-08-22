<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;
use StreetMesh\Server\Venue\Visitors;

/**
 * Who is at a party this second, as opposed to who belongs to one.
 *
 * Membership is in the database and survives a week away. This is the other
 * question, and it is the one a handshake needs: there is no point offering a
 * camera to somebody whose laptop is shut.
 *
 * It rides on the poll that was already fetching notes, so asking is also how a
 * browser says it is still here. Nothing is issued to anybody, which is the
 * point — a browser names its own connection, so nothing can be taken away from
 * it and coming back is saying the same name again.
 */
class PresenceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.parties.enabled', true);
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

    private function parties(): Parties
    {
        return $this->app->make(Parties::class);
    }

    /** @return array{0: Party, 1: Delegation, 2: Delegation} */
    private function partyOfTwo(): array
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->parties()->open($alice);
        $this->parties()->accept($this->parties()->invite($party, $alice, (string) $bob->did), $bob);

        return [$party, $alice, $bob];
    }

    private function as(Delegation $who): self
    {
        $this->withSession([Visitors::SESSION_KEY => $who->id]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(Delegation $who, Party $party, string $as, string $at = ''): array
    {
        return (array) $this->as($who)
            ->getJson(route('venue.parties.signals', $party->key).'?as='.$as.'&at='.urlencode($at))
            ->assertOk()
            ->json();
    }

    public function test_each_browser_hears_about_the_others_and_not_itself(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab');

        $answer = $this->poll($bob, $party, 'bob-tab');

        $this->assertCount(1, $answer['present']);
        $this->assertSame('alice-tab', $answer['present'][0]['id']);

        /*
         * The venue's word for who that is, not the browser's. A connection
         * names itself because a tab is the only thing that knows which tab it
         * is; it does not get to name the person, because that was settled at
         * the door.
         */
        $this->assertSame('alice.home.test', $answer['present'][0]['name']);
    }

    public function test_somebody_who_stops_asking_stops_being_mentioned(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab');
        $this->assertCount(1, $this->poll($bob, $party, 'bob-tab')['present']);

        /* Alice's laptop shuts. Nobody says anything; she simply stops asking. */
        $this->travel(6)->seconds();

        $this->assertCount(0, $this->poll($bob, $party, 'bob-tab')['present']);
    }

    /**
     * Because a timeout is what you fall back on when nobody said goodbye, and
     * closing a tab is a goodbye anybody can say.
     */
    public function test_saying_you_are_going_does_not_wait_to_be_missed(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab');
        $this->assertCount(1, $this->poll($bob, $party, 'bob-tab')['present']);

        $this->as($alice)->postJson(route('venue.parties.signal', $party->key), [
            'from' => 'alice-tab',
            'gone' => true,
        ])->assertOk();

        $this->assertCount(0, $this->poll($bob, $party, 'bob-tab')['present']);
    }

    /**
     * Coming back after being forgotten is not the same as never having left.
     *
     * Everybody else has already torn down the connection they had, so
     * rebuilding is cheaper than finding out one failed handshake at a time.
     */
    public function test_a_browser_that_lapsed_is_told_it_was_away(): void
    {
        [$party, $alice] = $this->partyOfTwo();

        $this->assertTrue($this->poll($alice, $party, 'alice-tab')['resumed'], 'the first ask is a return by definition');

        $this->assertFalse($this->poll($alice, $party, 'alice-tab')['resumed'], 'and asking again a moment later is not');

        $this->travel(6)->seconds();

        $this->assertTrue($this->poll($alice, $party, 'alice-tab')['resumed']);
    }

    public function test_one_party_cannot_see_another(): void
    {
        [$first, $alice, $bob] = $this->partyOfTwo();

        $carol = $this->visitor('carol');
        $second = $this->parties()->open($carol);

        $this->poll($bob, $first, 'bob-tab');
        $this->poll($carol, $second, 'carol-tab');

        $this->assertCount(1, $this->poll($alice, $first, 'alice-tab')['present'], 'only the party it was asked about');
        $this->assertSame(
            [],
            array_filter(
                $this->poll($alice, $first, 'alice-tab')['present'],
                fn (array $who): bool => $who['name'] === 'carol.home.test',
            ),
        );
    }

    /** Where somebody is rides along, because the poll was already going. */
    public function test_where_a_member_is_travels_with_them(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab', 'com.streetmesh.games.chess/ABC');

        $this->assertSame(
            'com.streetmesh.games.chess/ABC',
            $this->poll($bob, $party, 'bob-tab')['present'][0]['space'],
        );
    }

    /** A string somebody else chose, so it does not get to be any length. */
    public function test_a_place_is_not_a_novel(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab', str_repeat('x', 500));

        $this->assertSame(200, mb_strlen($this->poll($bob, $party, 'bob-tab')['present'][0]['space']));
    }

    /**
     * Where to fetch somebody's picture rides along, worked out by the venue.
     *
     * A handle is a hostname, so the address follows from the name — but the
     * venue does the following, not the browser. Turning a name that arrived
     * over the wire into an address a browser is sent to has one correct
     * implementation, and a second one in JavaScript would be a second
     * definition of a rule that has to hold.
     */
    public function test_each_person_arrives_with_the_address_of_their_own_face(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($alice, $party, 'alice-tab');

        $this->assertSame(
            'https://alice.home.test/avatar/icon',
            $this->poll($bob, $party, 'bob-tab')['present'][0]['icon'],
        );
    }

    /**
     * And a name that is not a hostname carries no address at all.
     *
     * Null is drawn as the letter it always was. What must never happen is a
     * browser being sent somewhere on the strength of a string.
     */
    public function test_a_name_that_could_not_be_a_host_carries_no_address(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $alice->update(['handle' => 'localhost']);

        $this->poll($alice, $party, 'alice-tab');

        $this->assertNull($this->poll($bob, $party, 'bob-tab')['present'][0]['icon']);
    }

    public function test_somebody_outside_the_party_never_appears_in_it(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->poll($bob, $party, 'bob-tab');

        $stranger = $this->visitor('mallory');

        $this->as($stranger)
            ->getJson(route('venue.parties.signals', $party->key).'?as=mallory-tab')
            ->assertNotFound();

        $this->assertCount(1, $this->poll($alice, $party, 'alice-tab')['present']);
    }
}
