<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Experiences\Audience;
use StreetMesh\Server\Venue\Experiences\Experience;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Gatherings\Gatherings;
use StreetMesh\Server\Venue\Visitors;

/**
 * The venue deciding who may be somewhere.
 *
 * This is the one authority the hub does not have. A hub can check that a venue
 * said somebody may sit down; only the venue can decide it, and only here is
 * that decision durable enough to survive the hub restarting.
 */
class GatheringTest extends TestCase
{
    private const CHESS = 'com.streetmesh.games.chess';

    private function gatherings(): Gatherings
    {
        return $this->app->make(Gatherings::class);
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

    /**
     * A delegation is one trip through the door, not a person.
     *
     * Coming back — tomorrow, or in another browser — mints a fresh one for the
     * same human. Keyed on the delegation, this venue sat one person down twice
     * and reported "2 at the table" with nobody else in the building. The
     * second chair was the first person returning, and they went on to play
     * themselves while a real visitor was put in the audience.
     */
    public function test_somebody_coming_back_returns_to_their_own_chair(): void
    {
        $gathering = $this->gatherings()->open('com.streetmesh.games.chess');
        $alice = $this->visitor();

        $first = $this->gatherings()->seat($gathering, $alice, 'white');

        // The same person, a second time through the door.
        $again = $this->visitor();

        $this->assertNotSame($alice->id, $again->id);
        $this->assertSame($alice->did, $again->did);

        $second = $this->gatherings()->seat($gathering, $again, 'black');

        $this->assertSame($first->id, $second->id, 'one person, one chair');
        $this->assertSame('white', $second->seat, 'and it is the chair they were already in');
        $this->assertSame(1, $gathering->seats()->count());
    }

    /**
     * The chair follows them to the permission they are actually holding.
     *
     * The one they sat down with may have expired while they were away, and
     * settling against it would fail at the last step — after the game was over
     * and there was nothing to be done about it.
     */
    public function test_the_chair_takes_up_the_permission_they_came_back_with(): void
    {
        $gathering = $this->gatherings()->open('com.streetmesh.games.chess');

        $this->gatherings()->seat($gathering, $this->visitor(), 'white');

        $again = $this->visitor();
        $seat = $this->gatherings()->seat($gathering, $again, 'white');

        $this->assertSame($again->id, $seat->delegation_id);
    }

    /**
     * The hub keeps nothing. A room is memory and is gone when the last person
     * leaves, so a venue that recorded only "concluded" could say a gathering
     * was over and nothing about it — which is what somebody coming back to
     * look at a finished game would be shown.
     */
    /**
     * An invitation goes out into the world and comes back through a message,
     * a mail client, a paste. Any of those may lower-case it or leave a space
     * on the end, and every one of them is still the same table.
     *
     * A key is a ULID — Crockford base32, case-insensitive by specification —
     * so this is reading the address correctly rather than being lenient.
     */
    public function test_a_link_that_travelled_still_finds_its_table(): void
    {
        $game = $this->gatherings()->open('com.example.pinball');

        foreach ([$game->key, strtolower($game->key), $game->key.' ', "  {$game->key}\n"] as $asked) {
            $this->assertSame(
                $game->id,
                Gathering::query()->keyed($asked)->first()?->id,
                "[{$asked}] should have found the table",
            );
        }
    }

    public function test_a_key_that_is_not_a_table_still_finds_nothing(): void
    {
        $this->gatherings()->open('com.example.pinball');

        $this->assertNull(Gathering::query()->keyed('01ANOTHERKEYENTIRELY000000')->first());
    }

    public function test_a_concluded_gathering_remembers_how_it_ended(): void
    {
        $gathering = $this->gatherings()->open('com.streetmesh.games.chess');

        $this->assertNull($gathering->outcome);

        $concluded = $this->gatherings()->conclude($gathering, [
            'outcome' => 'resignation',
            'winner' => 'white',
        ]);

        $this->assertSame('resignation', $concluded->outcome['outcome']);
        $this->assertSame('white', $concluded->outcome['winner']);
        $this->assertFalse($concluded->isOpen());
    }

    public function test_a_gathering_is_named_by_its_experience_and_which_one(): void
    {
        $gathering = $this->gatherings()->open(self::CHESS);

        $this->assertStringStartsWith(self::CHESS.'/', $gathering->room());
        $this->assertTrue($gathering->isOpen());
    }

    /**
     * A reload, a reconnection, a second tab — all of them arrive again, and
     * none of them should be an error or a second seat.
     */
    public function test_arriving_twice_is_the_same_seat(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);
        $alice = $this->visitor();

        $first = $gatherings->seat($gathering, $alice, 'white');
        $again = $gatherings->seat($gathering, $alice, 'white');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $gathering->seats()->count());
    }

    public function test_two_people_cannot_both_be_white(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);

        $gatherings->seat($gathering, $this->visitor('alice'), 'white');

        $this->expectException(RuntimeException::class);

        $gatherings->seat($gathering, $this->visitor('bob'), 'white');
    }

    /**
     * Somebody present but not playing. A watch party is all audience, and a
     * chess game has two players and everybody else.
     */
    public function test_several_people_can_be_present_without_a_seat(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);

        $gatherings->seat($gathering, $this->visitor('carol'));
        $gatherings->seat($gathering, $this->visitor('dave'));

        $this->assertSame(2, $gathering->seats()->count());
    }

    /**
     * The ticket says what the venue decided, not what the browser asked for.
     */
    public function test_a_ticket_carries_the_seat_the_venue_gave(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);
        $alice = $this->visitor();

        $gatherings->seat($gathering, $alice, 'white');

        $claims = json_decode(
            (string) base64_decode(strtr(explode('.', $gatherings->admit($gathering, $alice))[1], '-_', '+/'), true),
            true,
        );

        $this->assertSame('white', $claims['seat']);
        $this->assertSame($gathering->room(), $claims['room']);
        $this->assertSame($alice->did, $claims['sub']);
    }

    public function test_somebody_with_no_place_there_is_given_no_way_in(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);

        $gatherings->seat($gathering, $this->visitor('alice'), 'white');

        $this->expectException(RuntimeException::class);

        $gatherings->admit($gathering, $this->visitor('stranger'));
    }

    public function test_nothing_gets_a_way_into_something_that_is_over(): void
    {
        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);
        $alice = $this->visitor();

        $gatherings->seat($gathering, $alice, 'white');
        $gatherings->conclude($gathering);

        $this->expectException(RuntimeException::class);

        $gatherings->admit($gathering->refresh(), $alice);
    }

    // ── And the endpoint a browser actually calls ────────────────────────────

    public function test_a_visitor_is_handed_a_ticket_and_somewhere_to_take_it(): void
    {
        config()->set('streetmesh.venue.hub', 'wss://hub.games.test');

        $gatherings = $this->gatherings();
        $gathering = $gatherings->open(self::CHESS);
        $alice = $this->visitor();

        $gatherings->seat($gathering, $alice, 'white');

        session([Visitors::SESSION_KEY => $alice->id]);

        $this->post(route('venue.ticket', $gathering->key))
            ->assertOk()
            ->assertJsonPath('room', $gathering->room())
            ->assertJsonPath('hub', 'wss://hub.games.test')
            ->assertJsonPath('experience', self::CHESS);
    }

    /**
     * Silence is read as "no".
     *
     * An experience nothing installed cannot be asked who may watch, and the
     * only safe reading of that is the closed one. This is what a package
     * having been removed looks like, and it must not turn every gathering it
     * left behind into a public one.
     */
    public function test_nobody_visiting_is_handed_nothing(): void
    {
        $gathering = $this->gatherings()->open(self::CHESS);

        $this->post(route('venue.ticket', $gathering->key))->assertForbidden();
    }

    /**
     * A passer-by, at something whose experience says anybody may look.
     *
     * No door, and nothing pressed first: they followed a link to a game and
     * they are watching it. The ticket seats them nowhere, which is what keeps
     * this from being a way to join in.
     */
    public function test_a_stranger_may_watch_what_is_open_to_anybody(): void
    {
        $gathering = $this->openTo(Audience::Anybody);

        $response = $this->post(route('venue.ticket', $gathering->key))
            ->assertOk()
            ->assertJsonPath('room', $gathering->room());

        $this->assertSame('', $this->claims($response->json('ticket'))['seat']);
    }

    /**
     * And the same gathering, kept to the people at it.
     */
    public function test_a_stranger_is_refused_what_is_kept_to_its_players(): void
    {
        $gathering = $this->openTo(Audience::Players);

        $this->post(route('venue.ticket', $gathering->key))->assertForbidden();
    }

    /**
     * Somebody who came through the door but has no place at this table.
     *
     * Watching under their own name rather than anonymously: the venue knows
     * who they are, and saying otherwise would be inventing a stranger.
     */
    public function test_a_visitor_watching_is_named(): void
    {
        $gathering = $this->openTo(Audience::Anybody);

        session([Visitors::SESSION_KEY => $this->visitor('carol')->id]);

        $claims = $this->claims(
            $this->post(route('venue.ticket', $gathering->key))->assertOk()->json('ticket')
        );

        $this->assertSame('', $claims['seat']);
        $this->assertStringContainsString('carol', $claims['name']);
    }

    /**
     * Open something whose experience gives a particular answer about who may
     * watch it.
     */
    private function openTo(Audience $audience): Gathering
    {
        app(Experiences::class)->register(new class($audience) implements Experience
        {
            public function __construct(private readonly Audience $who) {}

            public function audience(Gathering $gathering): Audience
            {
                return $this->who;
            }

            /**
             * @return array{label: string, route: string}|null
             */
            public function watching(): ?array
            {
                return null;
            }

            public function name(): string
            {
                return 'com.example.watched';
            }

            public function title(): string
            {
                return 'Watched';
            }

            public function description(): string
            {
                return 'Something to look at';
            }

            public function icon(): string
            {
                return 'eye';
            }

            public function route(): string
            {
                return 'venue.experiences';
            }

            public function action(): ?string
            {
                return null;
            }

            /**
             * @return array<int, string>
             */
            public function scopes(): array
            {
                return [];
            }

            public function room(): ?string
            {
                return null;
            }
        });

        return $this->gatherings()->open('com.example.watched');
    }

    /**
     * What a ticket says, without checking the signature — that is the hub's
     * job, and it has its own checks for it.
     *
     * @return array<string, mixed>
     */
    private function claims(string $ticket): array
    {
        return (array) json_decode(
            (string) base64_decode(strtr(explode('.', $ticket)[1], '-_', '+/'), true),
            true,
        );
    }

    /**
     * Being a visitor is not the same as belonging at a particular table.
     */
    public function test_a_visitor_with_no_place_there_is_refused(): void
    {
        $gathering = $this->gatherings()->open(self::CHESS);

        session([Visitors::SESSION_KEY => $this->visitor('stranger')->id]);

        $this->post(route('venue.ticket', $gathering->key))->assertForbidden();
    }

    public function test_a_gathering_nobody_opened_is_not_found(): void
    {
        session([Visitors::SESSION_KEY => $this->visitor()->id]);

        $this->post(route('venue.ticket', 'nothing-by-that-name'))->assertNotFound();
    }
}
