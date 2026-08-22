<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Comms;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Visitors;

/**
 * The comms widget, as three documents.
 *
 * Each is served into an iframe of its own, and that is the shape rather than
 * an implementation detail: the badge sits above every experience's screen
 * without inheriting its stylesheet or its stacking context, and the stage
 * holds a live microphone that a re-render of somebody's game must not be able
 * to take away.
 *
 * What is worth testing from here is what each document is *for* — that the
 * badge is a badge and nothing else, that the stage knows which party it
 * belongs to, and that the panel offers the right two conversations.
 */
class CommsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.parties.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * These documents load the application's built assets, and a package
         * has no build. What is being tested is what they say rather than what
         * they load, so the tags are stubbed out — the alternative is a
         * manifest checked into a package that would go stale on its own.
         */
        $this->withoutVite();
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
     * @return TestResponse<Response>
     */
    private function as(Delegation $who, string $surface): TestResponse
    {
        return $this->withSession([Visitors::SESSION_KEY => $who->id])
            ->get(route('venue.comms.'.$surface));
    }

    private function parties(): Parties
    {
        return $this->app->make(Parties::class);
    }

    /**
     * Somewhere to look at the snippet a host layout includes.
     *
     * The widget belongs in an application's chrome, which a package has none
     * of — but what it writes into the page is now the thing that matters most,
     * because the page is what holds the camera.
     */
    protected function defineRoutes($router): void
    {
        $router->get('comms-host', fn () => View::make('venue::comms.widget'))->middleware('web');
    }

    public function test_the_badge_is_a_circle_and_nothing_else(): void
    {
        $badge = $this->get(route('venue.comms.badge'))->assertOk();

        $badge->assertSee('border-radius: 9999px', escape: false);

        /*
         * Deliberately self-contained. The badge is the first thing anybody
         * sees, and pulling the application's whole stylesheet in for one
         * circle would make it the slowest.
         */
        $badge->assertDontSee('livewire', escape: false);
    }

    /**
     * A passer-by gets a badge with an invitation behind it rather than an
     * empty panel. Watching a public game asks nothing of anybody, so the
     * corner of the screen should not be the thing that starts demanding.
     */
    public function test_somebody_who_has_not_arrived_is_offered_the_door(): void
    {
        $this->get(route('venue.comms.panel'))
            ->assertOk()
            ->assertSee('Talking needs a name')
            ->assertSee(route('venue.connect'), escape: false);
    }

    public function test_the_panel_offers_both_conversations(): void
    {
        $this->as($this->visitor(), 'panel')
            ->assertOk()
            ->assertSee('Room')
            ->assertSee('Party');
    }

    public function test_the_party_tab_offers_the_two_ways_in(): void
    {
        $this->as($this->visitor(), 'panel')
            ->assertOk()
            ->assertSee('Start a party')
            ->assertSee('Code');
    }

    /**
     * The camera and microphone are reachable without a party.
     *
     * They started inside the party branch, which is where they were useless:
     * your own circle only appears once something is turned on, so somebody
     * with no party and nothing on had no switch anywhere on screen and no way
     * to get started at all. A camera is yours rather than the party's.
     */
    public function test_the_camera_can_be_turned_on_without_a_party(): void
    {
        $panel = $this->as($this->visitor(), 'panel')->assertOk();

        /*
         * The words are the accessible name now rather than a label, because
         * the buttons are icons — so they are asserted where they actually
         * live, which is also the only place anything but a pair of eyes will
         * find them.
         */
        $panel->assertSee('aria-label="Speak"', escape: false);
        $panel->assertSee('aria-label="Show"', escape: false);
        $panel->assertSee('streetmesh.panel.speak', escape: false);
        $panel->assertSee('streetmesh.panel.show', escape: false);
    }

    /**
     * And nowhere at all at a venue that offers no parties.
     *
     * They used to be on both tabs, which put a microphone under a text
     * conversation and offered a camera at a venue with nobody to point it at:
     * media is carried between the people in a party, so with no parties on
     * offer these switch on a preview of yourself and nothing else.
     *
     * Still reachable before a party exists — they follow the party *tab*,
     * which is there whenever parties are, rather than a party, which is not.
     */
    public function test_the_switches_are_not_offered_where_parties_are_not(): void
    {
        config(['streetmesh.venue.parties.enabled' => false]);

        $this->as($this->visitor(), 'panel')
            ->assertOk()
            ->assertDontSee('Start a party')
            ->assertDontSee('streetmesh.panel.speak', escape: false)
            ->assertDontSee('streetmesh.panel.show', escape: false);
    }

    /**
     * A word you can say across a table. Being able to do that is the whole
     * reason it exists, so it has to be on the panel where the party is.
     *
     * It lives in the drawer, which is drawn shut rather than not drawn — the
     * conversation is what the pane is for, and this is reference you read
     * once. So is the way out: leaving is rare, and putting it on show under
     * the conversation cost the room a strip that was mostly empty.
     */
    public function test_a_party_shows_the_word_that_lets_somebody_in(): void
    {
        $alice = $this->visitor();
        $party = $this->parties()->open($alice);

        $this->as($alice, 'panel')
            ->assertOk()
            ->assertSee($party->code)
            ->assertSee('Copy')
            ->assertSee('Really leave?')
            ->assertSee('Leave party');
    }

    /**
     * A switch that is off says so, rather than merely failing to say it is on.
     *
     * Colour alone asks somebody to remember what the other state looked like,
     * and lit-versus-unlit is exactly the distinction a person glancing at
     * their own microphone cannot afford to get wrong. So off is the outlined
     * weight and on is the filled one.
     *
     * Asserted on what is drawn rather than on what asks for it: `variant` is a
     * prop, consumed by the component, and never reaches the page — an earlier
     * version of this test looked for it, passed, and proved nothing.
     */
    public function test_a_switch_that_is_off_is_drawn_differently(): void
    {
        $panel = $this->as($this->visitor(), 'panel')->assertOk();

        /* Both weights are on the page and one is hidden, because a Blade prop
           cannot follow a state the browser is holding. */
        $panel->assertSee('M8.25 4.5a3.75 3.75 0 1 1 7.5 0v8.25', escape: false);
        $panel->assertSee('M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5', escape: false);

        $panel->assertSee('x-show="! speaking"', escape: false);
        $panel->assertSee('x-show="speaking"', escape: false);
        $panel->assertSee('x-show="! showing"', escape: false);
        $panel->assertSee('x-show="showing"', escape: false);
    }

    /**
     * And a way to open it, beside the microphone and the camera.
     *
     * The three things this panel does, in one row.
     */
    public function test_the_party_drawer_has_a_switch_of_its_own(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $panel = $this->as($alice, 'panel')->assertOk();

        $panel->assertSee('aria-label="Who is here"', escape: false);
        $panel->assertSee('fold(! drawer)', escape: false);
    }

    /**
     * A drawer that opens itself can still be shut.
     *
     * Starting a party opens it, because the word that lets anybody else in is
     * what somebody obviously came for. That used to be a property saying
     * "open", and a property goes on saying it: every poll rendered it again,
     * so a drawer somebody had just shut opened by itself a few seconds later.
     *
     * It is an event now, which happens once, and whether it is open is the
     * browser's to remember.
     */
    public function test_opening_the_drawer_is_said_once_rather_than_kept(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor()->id]);

        Livewire::test('venue::comms')
            ->call('start')
            ->assertDispatched('comms-drawer');
    }

    /** And no switch where there is no party to look into. */
    public function test_there_is_no_switch_before_there_is_a_party(): void
    {
        $this->as($this->visitor(), 'panel')
            ->assertOk()
            ->assertDontSee('aria-label="Who is here"', escape: false);
    }

    /**
     * Reading and arranging are two modes, and the panel says which it is in.
     *
     * The settings used to rise over the bottom of the conversation with the
     * tabs still lit behind them, and the only thing distinguishing the two was
     * that one was faded — a state a reader has to work out rather than see.
     * They cover the panel now, and the half underneath is made unreachable
     * rather than merely hidden.
     */
    public function test_the_panel_wears_one_header_or_the_other(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $panel = $this->as($alice, 'panel')->assertOk();

        $panel->assertSee('Party Settings');

        // Laid over the conversation rather than beside it, so it can slide.
        $panel->assertSee('absolute inset-0 flex flex-col bg-white', escape: false);
        $panel->assertSee('x-show="settings()"', escape: false);
    }

    /**
     * What is covered is out of reach, not merely out of sight.
     *
     * Something hidden by an overlay is still in the document, still focusable
     * and still read aloud — and a tab strip a keyboard can reach but an eye
     * cannot is the confusion this arrangement exists to end. It settles the
     * older mistake in the same attribute: the faded conversation that was
     * still live.
     */
    public function test_what_the_settings_cover_cannot_be_reached(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $this->as($alice, 'panel')->assertOk()
            ->assertSee('x-bind:inert="settings()"', escape: false);
    }

    /**
     * The panel it slides over is drawn before there is anything to put in it.
     *
     * Alpine runs `x-show`'s first evaluation without a transition, so an
     * element born already-shown is simply shown. While this was conditional on
     * having a party, starting one created it with the switch already thrown
     * and it appeared rather than slid — it only ever animated for a party that
     * existed before the panel did.
     */
    public function test_the_settings_exist_before_the_party_does(): void
    {
        $panel = $this->as($this->visitor(), 'panel')->assertOk();

        // The element and its header, with no party anywhere.
        $panel->assertSee('x-transition:enter-start="translate-y-full"', escape: false);
        $panel->assertSee('Party Settings');

        // And nothing inside it that would need one.
        $panel->assertDontSee('Anybody can join with this word');
    }

    /**
     * Leaving puts them away.
     *
     * Whether they are open is the browser's to remember, and it remembers
     * across reloads — so somebody who left from in here would otherwise carry
     * "open" into the next party they joined and land in its settings rather
     * than its conversation.
     */
    public function test_leaving_a_party_shuts_its_settings(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $this->withSession([Visitors::SESSION_KEY => $alice->id]);

        Livewire::test('venue::comms')
            ->call('leave')
            ->assertDispatched('comms-drawer-close');
    }

    /**
     * It slides, and it slides the way its caret points.
     *
     * Transformed rather than resized: animating a height reflows the
     * conversation behind the whole way, which on a chat anchored to its foot
     * reads as the text scrolling itself. And it stays instant for anybody who
     * has asked their machine to stop moving things.
     */
    public function test_the_settings_slide_rather_than_appear(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $panel = $this->as($alice, 'panel')->assertOk();

        $panel->assertSee('x-transition:enter-start="translate-y-full"', escape: false);
        $panel->assertSee('x-transition:leave-end="translate-y-full"', escape: false);
        $panel->assertSee('motion-reduce:transition-none', escape: false);
    }

    /**
     * The caret takes the close button's place rather than sitting beside it.
     *
     * One control in that corner, always meaning "put this back" — what "this"
     * is follows from what the header says. Closing the panel outright is still
     * one press away from the button that opened the settings.
     */
    public function test_the_way_back_is_where_the_way_out_was(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $this->as($alice, 'panel')->assertOk()
            ->assertSee('aria-label="Back to the conversation"', escape: false)
            ->assertSee('x-on:click="fold(false)"', escape: false);
    }

    /**
     * Session storage outlives the panel, so the mode cannot rest on it alone.
     *
     * A stale "open" plus a reload onto the room tab, or into a party somebody
     * has since left, would otherwise put a settings header over a panel with
     * nothing underneath it.
     */
    public function test_the_mode_needs_a_party_and_the_tab_to_match(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $this->as($alice, 'panel')->assertOk()
            ->assertSee("this.drawer && this.tab === 'party' && this.\$wire.inParty", escape: false);
    }

    /**
     * The conversation is not dimmed, because it is not there.
     *
     * Fading it said "this is not what you are talking to" and was the reason
     * the thing behind had to stay live, which was worse than the fade.
     */
    public function test_the_conversation_is_replaced_rather_than_faded(): void
    {
        $alice = $this->visitor();
        $this->parties()->open($alice);

        $this->as($alice, 'panel')->assertOk()
            ->assertDontSee("drawer ? 'opacity-50' : ''", escape: false);
    }

    /**
     * The settings say how many people are in the party.
     *
     * Beside the way out, because "who is here" and "I am going" are the two
     * things somebody opens this to settle.
     */
    public function test_the_settings_say_how_many_are_in_the_party(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->parties()->open($alice);
        $this->parties()->accept($this->parties()->invite($party, $alice, (string) $bob->did), $bob);

        $this->as($alice, 'panel')->assertOk()->assertSee('Party of 2');
    }

    /**
     * The stage holds nothing and knows nothing.
     *
     * It used to hold the media, and could not: a navigation reloads every
     * iframe on the page, and a stream cannot outlive the document that
     * acquired it. What is left is a stylesheet and somewhere to put faces —
     * the page around it draws them, which it may do because they are the same
     * origin.
     */
    public function test_the_stage_is_a_shell(): void
    {
        $this->as($this->visitor(), 'stage')
            ->assertOk()
            ->assertSee('id="stage"', escape: false)
            ->assertDontSee('streetmeshStage', escape: false)
            ->assertDontSee('stage-frame', escape: false);
    }

    /**
     * A circle can show that its person could not be reached.
     *
     * Including the `[hidden]` rule for it, which is not a detail. A `display`
     * set anywhere else beats the attribute, and what that looks like is two
     * marks contradicting each other on one circle — the same fault that once
     * showed both chat icons at the same time.
     */
    public function test_a_face_can_show_that_nobody_could_reach_it(): void
    {
        $this->as($this->visitor(), 'stage')
            ->assertOk()
            ->assertSee('.face .lost {', escape: false)
            ->assertSee('.face .lost[hidden]', escape: false)
            ->assertSee('.face.lost .portrait', escape: false);
    }

    /**
     * Each surface says it may be framed by this origin.
     *
     * Nothing local objects to framing, so this was found only once it was
     * deployed: a proxy in front of the venue added `X-Frame-Options: deny` to
     * everything it served, which refuses same-origin framing as flatly as any
     * other kind. All three came back 200 and none of them appeared.
     *
     * `frame-ancestors` is the one that decides it — a browser reading both
     * applies the policy and ignores the older header — which is why it is
     * asserted here even though the proxy's own answer is the one that will
     * arrive beside it.
     */
    public function test_the_surfaces_say_they_may_be_framed(): void
    {
        foreach (['badge', 'panel', 'stage'] as $surface) {
            $this->as($this->visitor(), $surface)
                ->assertOk()
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        }
    }

    /**
     * The party's addresses go to the page, because the page is what will be
     * using them.
     */
    public function test_the_page_is_told_which_party_to_join(): void
    {
        $alice = $this->visitor();
        $party = $this->parties()->open($alice);

        $this->withSession([Visitors::SESSION_KEY => $alice->id])
            ->get('/comms-host')
            ->assertOk()
            ->assertSee($party->key)
            ->assertSee(route('venue.parties.ticket', $party->key), escape: false)
            ->assertSee(route('venue.parties.signals', $party->key), escape: false)
            ->assertSee($alice->handle);
    }

    public function test_somebody_in_no_party_gives_the_page_nothing_to_join(): void
    {
        $this->withSession([Visitors::SESSION_KEY => $this->visitor()->id])
            ->get('/comms-host')
            ->assertOk()
            ->assertSee('"party":null', escape: false);
    }

    /**
     * A server that is not a venue has none of this, whatever the setting says.
     *
     * The widget is included by the application's chrome, which a domicile
     * draws too — and the routes behind it belong to the venue package's own
     * group, which is never registered on a server that is not a venue. Left to
     * the setting alone, a domicile emitted three frames pointing at three
     * addresses it does not have.
     */
    public function test_a_server_that_is_not_a_venue_offers_nothing(): void
    {
        config(['streetmesh.venue.comms.enabled' => true]);

        /* The switch the operator sets, which is settled before anything
           boots — see `Capabilities::offers`. */
        $this->app->instance(
            Capabilities::class,
            new Capabilities(['venue' => false]),
        );

        $this->assertFalse($this->app->make(Comms::class)->offered());
    }

    public function test_a_venue_with_comms_off_has_none(): void
    {
        config(['streetmesh.venue.comms.enabled' => false]);

        $this->get(route('venue.comms.badge'))->assertNotFound();
        $this->get(route('venue.comms.panel'))->assertNotFound();
        $this->get(route('venue.comms.stage'))->assertNotFound();
    }
}
