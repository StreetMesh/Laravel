<?php

namespace StreetMesh\Server\Tests;

use Livewire\Livewire;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Experiences\Audience;
use StreetMesh\Server\Venue\Experiences\Experience;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Visitors;

/**
 * The panel, asked again five seconds later.
 *
 * It polls, so whatever it does it does forever — and a failure there is not a
 * page that will not load but a black rectangle over one that already did,
 * arriving out of nowhere at whatever moment the poll happened to catch.
 */
class PanelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.parties.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function visitor(string $who): Delegation
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
     * A tab somebody picked is not un-picked by the page.
     *
     * The panel follows the page until somebody chooses, and the two arrive as
     * separate requests — so a `comms-context` landing in the gap before a
     * choice registers used to re-decide the default and send the reader back
     * where they came from. Clicking a tab switched it and then switched it
     * back, a moment later, for no reason visible on screen.
     */
    public function test_the_page_stops_choosing_once_the_reader_has(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        /*
         * Set rather than called, because that is what the tab strip does: it
         * hands the server the two properties without asking for a render,
         * since a render would write over the classes Alpine had just put on
         * the tab that was tapped.
         */
        Livewire::test('venue::comms')
            ->set('tab', 'room')
            ->set('chosen', true)
            ->call('context', 'chess/table/1', 'Table 1')
            ->assertNotDispatched('comms-tab')
            ->assertSet('tab', 'room');
    }

    /**
     * The room tab is named for the experience, not for the screen.
     *
     * A page knows it is a lobby or a table and is right to; a tab saying
     * "Lobby" and then "Table" reads as the conversation having changed, when
     * it is the same room and the same people. What distinguishes this tab from
     * the other one is whose conversation it is.
     */
    public function test_the_room_tab_is_named_for_the_experience(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        app(Experiences::class)->register(new NamedExperience);

        Livewire::test('venue::comms')
            ->call('context', 'com.example.game/lobby', 'Lobby')
            ->assertSet('spaceLabel', 'Draughts')
            ->call('context', 'com.example.game/01JQTABLE', 'Table')
            ->assertSet('spaceLabel', 'Draughts');
    }

    /** And anywhere that is not an experience keeps the word the page gave. */
    public function test_somewhere_that_is_not_an_experience_keeps_its_own_name(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        Livewire::test('venue::comms')
            ->call('context', 'somewhere/else', 'Somewhere else')
            ->assertSet('spaceLabel', 'Somewhere else');
    }

    /** Until they have, it follows the page — which is what makes the above worth having. */
    public function test_the_page_chooses_until_somebody_does(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        Livewire::test('venue::comms')
            ->call('context', 'chess/table/1', 'Table 1')
            ->assertDispatched('comms-tab')
            ->assertSet('tab', 'room');
    }

    /**
     * The browser is told where to start, and reads its own answer first.
     *
     * Everything above is the server's half. The half that actually decides is
     * in the browser, because this element polls and every poll re-writes the
     * attribute that holds it — so the tab is kept where it was chosen rather
     * than in memory that the next re-render throws away.
     */
    public function test_the_panel_reads_the_chosen_tab_from_the_browser(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        Livewire::test('venue::comms')
            ->assertSeeHtml("sessionStorage.getItem('smCommsTab')")
            ->assertSeeHtml('suggest($event.detail.tab)');
    }

    /**
     * The panel has its own answer for not reaching the server.
     *
     * Livewire's is a dialog inset fifty pixels from every edge holding an
     * iframe painted `#17161A`, into which it writes the response body — and a
     * request that never completed has no body, so what a machine waking up to
     * a dead connection produces is a black rectangle containing nothing.
     */
    public function test_the_panel_answers_a_failed_poll_itself(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        $this->get(route('venue.comms.panel'))
            ->assertOk()
            ->assertSee('id="streetmesh-trouble"', escape: false)
            ->assertSee('preventDefault()', escape: false)
            ->assertSee('Reconnecting', escape: false);
    }

    /**
     * A person who cannot be reached is said so, in words.
     *
     * Two browsers on networks that cannot see each other produce an empty
     * circle — which is also what somebody who has not turned a camera on
     * produces, and what every signalling bug so far produced. One appearance
     * for four meanings is why this failure has been mistaken for a bug three
     * times.
     */
    public function test_the_panel_can_say_somebody_is_unreachable(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        Livewire::test('venue::comms')
            ->assertSeeHtml('unreachable: window.smUnreachable')
            ->assertSeeHtml('comms-unreachable.window')
            ->assertSeeHtml('cannotReach()');
    }

    /**
     * And the party failing outright, which until now was announced to nobody.
     *
     * `streetmesh.stage.trouble` has been broadcast by the page since before
     * any of this — "your party would not let you in", "could not reach the
     * party's room", "this browser would not give us the camera" — and no
     * document anywhere listened for it.
     */
    public function test_the_panel_hears_about_the_party_failing(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor('alice')->id]);

        $this->get(route('venue.comms.panel'))
            ->assertOk()
            ->assertSee('streetmesh.stage.trouble', escape: false)
            ->assertSee('streetmesh.stage.unreachable', escape: false);
    }

    public function test_the_panel_survives_being_polled_with_two_in_the_party(): void
    {
        $alice = $this->visitor('alice');
        $bob = $this->visitor('bob');

        $parties = $this->app->make(Parties::class);
        $party = $parties->open($alice);
        $parties->accept($parties->invite($party, $alice, (string) $bob->did), $bob);

        $this->withSession([Visitors::SESSION_KEY => $alice->id]);

        $panel = Livewire::test('venue::comms');

        $panel->assertOk();
        $panel->call('$refresh')->assertOk();
        $panel->call('$refresh')->assertOk();
    }

    public function test_the_panel_survives_a_poll_after_the_other_one_leaves(): void
    {
        $alice = $this->visitor('alice');
        $bob = $this->visitor('bob');

        $parties = $this->app->make(Parties::class);
        $party = $parties->open($alice);
        $parties->accept($parties->invite($party, $alice, (string) $bob->did), $bob);

        $this->withSession([Visitors::SESSION_KEY => $alice->id]);

        $panel = Livewire::test('venue::comms');
        $panel->assertOk();

        $parties->leave($party, $bob);

        $panel->call('$refresh')->assertOk();
    }

    /**
     * The poll that arrives after the party is gone entirely.
     *
     * The likeliest shape of this failure: a component holding a party that no
     * longer exists, asked to draw itself again before anything told it so.
     */
    public function test_the_panel_survives_a_poll_after_the_party_disbands(): void
    {
        $alice = $this->visitor('alice');
        $bob = $this->visitor('bob');

        $parties = $this->app->make(Parties::class);
        $party = $parties->open($alice);
        $parties->accept($parties->invite($party, $alice, (string) $bob->did), $bob);

        $this->withSession([Visitors::SESSION_KEY => $alice->id]);

        $panel = Livewire::test('venue::comms');
        $panel->assertOk();

        $parties->disband($party);

        $panel->call('$refresh')->assertOk();
    }
}

/**
 * Something installed, so the venue has a name to answer with.
 *
 * The venue package ships no experiences — chess is somebody else's package —
 * so a test about naming one has to bring one.
 */
final class NamedExperience implements Experience
{
    public function name(): string
    {
        return 'com.example.game';
    }

    public function title(): string
    {
        return 'Draughts';
    }

    public function description(): string
    {
        return 'A game of draughts.';
    }

    public function icon(): string
    {
        return 'puzzle-piece';
    }

    public function route(): string
    {
        return '/draughts';
    }

    public function action(): ?string
    {
        return null;
    }

    public function scopes(): array
    {
        return [];
    }

    public function room(): ?string
    {
        return null;
    }

    public function watching(): ?array
    {
        return null;
    }

    public function audience(Gathering $gathering): Audience
    {
        return Audience::Players;
    }
}
