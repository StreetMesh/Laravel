<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Tests\Fixtures\Resident;
use StreetMesh\Server\Venue\Experiences\Audience;
use StreetMesh\Server\Venue\Experiences\Experience;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Http\ConnectController;
use StreetMesh\Server\Venue\VenueCapability;
use StreetMesh\Server\Venue\Visitors;

class VenueTest extends TestCase
{
    /**
     * A server that gathers people and does not house them.
     *
     * This used to be a fact about what was installed: the venue was its own
     * package, so a suite that booted only it described a venue-only server for
     * free. One package ships both halves now, and which of them a server
     * offers is the operator's line in a config file — so the tests that are
     * about a venue-only server have to say that, and saying it is the point
     * rather than an inconvenience.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.capabilities.domicile', false);
    }

    private function capabilities(): Capabilities
    {
        return $this->app->make(Capabilities::class);
    }

    public function test_a_server_that_offers_only_this_says_it_hosts_gatherings(): void
    {
        $this->assertTrue($this->capabilities()->has('venue'));
        $this->assertSame(['venue'], $this->capabilities()->names());
    }

    /**
     * The wire and the interface read one list, so they cannot come to disagree
     * about what this server does.
     */
    public function test_the_did_document_says_so_too(): void
    {
        $document = $this->get('/.well-known/did.json')->assertOk()->json();

        $this->assertSame('StreetMeshVenue', $document['service'][0]['type']);
    }

    public function test_it_offers_a_front_page_without_claiming_the_root(): void
    {
        $this->assertSame('venue::front', $this->capabilities()->get('venue')->frontPage());

        /*
         * Offering is not taking. Installed on its own, this package leaves the
         * root empty — because there is one of it however many capabilities are
         * present, and a package claiming it would win or lose on boot order
         * with nobody deciding.
         */
        $this->get('/')->assertNotFound();
    }

    public function test_it_offers_a_panel_for_a_home_page_it_does_not_own(): void
    {
        $widgets = $this->capabilities()->widgets();

        $this->assertCount(1, $widgets);
        $this->assertSame('venue.experiences', $widgets[0]->name());
    }

    /**
     * Packages get installed and removed. A page must not fail to render
     * because a configuration file still names one that left.
     */
    public function test_an_arrangement_naming_nothing_is_skipped_rather_than_fatal(): void
    {
        $this->assertSame([], $this->capabilities()->widgets(['a.package.that.left']));
        $this->assertCount(1, $this->capabilities()->widgets(['venue.experiences', 'gone']));
    }

    public function test_its_own_screen_is_at_its_own_name(): void
    {
        $this->seated();

        $this->get('/experiences')
            ->assertOk()
            ->assertSee('What there is to do here.')
            ->assertSee('Nothing installed yet');
    }

    /**
     * The screen is a Livewire component this package ships, not a view the
     * host has to know about.
     *
     * Worth asserting rather than assuming, because Livewire keeps a register
     * of component namespaces separate from Blade's, and a package that
     * registers only the Blade one gets a view that resolves and a component
     * that does not — which is how this was first built.
     */
    public function test_it_ships_its_own_livewire_component(): void
    {
        $this->assertNotNull(
            $this->app->make('livewire.finder')->resolveSingleFileComponentPath('venue::experiences')
        );

        $this->seated();

        // A Livewire component rather than a plain view, which is what the
        // snapshot attribute proves — the screen itself has no form controls
        // to point at now that it is a gallery.
        $this->get('/experiences')->assertSee('wire:snapshot', escape: false);
    }

    /**
     * A menu is a thing venues put where people can read it.
     */
    public function test_anybody_may_read_the_menu_by_default(): void
    {
        $this->get('/experiences')
            ->assertOk()
            ->assertSee('What there is to do here.');
    }

    /**
     * And a venue that would rather not say what it hosts can keep it shut.
     */
    public function test_a_venue_can_put_the_menu_behind_the_door(): void
    {
        config()->set('streetmesh.venue.gallery', 'visitors');

        $this->get('/experiences')->assertRedirect(route('venue.connect'));
    }

    /**
     * Being asked to identify yourself and then dumped at the entrance is the
     * small rudeness that makes a federated arrival feel worse than a local
     * sign-in.
     */
    public function test_where_somebody_was_heading_survives_being_sent_home_to_be_asked(): void
    {
        config()->set('streetmesh.venue.gallery', 'visitors');

        $this->get('/experiences');

        $this->assertSame(url('/experiences'), session(Visitors::INTENDED_KEY));
    }

    /**
     * But only somewhere a person was going.
     *
     * Everything guarded used to write down where it was heading, and the panel
     * polls one of those once a second for as long as it is open — so revoking
     * a permission left the last poll as "where you were heading", and signing
     * back in delivered somebody to a JSON endpoint belonging to the party
     * system. Nothing looks broken until the very end, which is what makes it
     * worth a test rather than a fix.
     */
    public function test_a_script_asking_is_not_somewhere_to_be_returned_to(): void
    {
        config()->set('streetmesh.venue.gallery', 'visitors');

        session([Visitors::INTENDED_KEY => url('/experiences/chess')]);

        // The shape the comms panel's poll arrives in.
        $this->withHeaders(['Accept' => 'application/json', 'Sec-Fetch-Dest' => 'empty'])
            ->get('/experiences');

        $this->assertSame(
            url('/experiences/chess'),
            session(Visitors::INTENDED_KEY),
            'a poll overwrote where somebody was actually going',
        );
    }

    /** Nor a frame, which is a part of a page rather than one. */
    public function test_a_frame_loading_is_not_somewhere_to_be_returned_to(): void
    {
        config()->set('streetmesh.venue.gallery', 'visitors');

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get('/experiences');

        $this->assertNull(session(Visitors::INTENDED_KEY));
    }

    /** A browser that says nothing about what it wanted is still believed. */
    public function test_a_browser_that_does_not_say_is_taken_at_its_word(): void
    {
        config()->set('streetmesh.venue.gallery', 'visitors');

        $this->get('/experiences');

        $this->assertSame(url('/experiences'), session(Visitors::INTENDED_KEY));
    }

    /**
     * Pressing Continue says so, and stops taking presses.
     *
     * What follows is not a page on this server — the venue goes off to find
     * whichever server was named and starts a handshake with it, which takes as
     * long as that server takes. A second press in the meantime starts a second
     * handshake.
     *
     * Flux draws the waiting state given a submit button carrying `disabled`;
     * what is asserted here is the part it cannot supply, which is the moment.
     */
    public function test_continue_shows_it_is_working_and_refuses_a_second_press(): void
    {
        $this->get('/connect')
            ->assertOk()
            ->assertSee('x-on:submit="$refs.go.disabled = true"', escape: false)
            ->assertSee('x-ref="go"', escape: false)

            /* Flux's own spinner, which is only in the markup because this is a
               submit button that renders without `disabled`. */
            ->assertSee('data-flux-loading-indicator', escape: false)

            /* And undone by a browser restoring this page from its cache, which
               is exactly what Back does after leaving for another server. */
            ->assertSee('x-on:pageshow.window="$refs.go.disabled = false"', escape: false);
    }

    public function test_the_door_asks_for_an_address_and_offers_no_account(): void
    {
        $this->get('/connect')
            ->assertOk()
            ->assertSee('Your address')
            ->assertSee('Sign in with your StreetMesh account.')
            ->assertSee('Continue')

            /*
             * No password, and no sign of the building. This is a door: the
             * host's auth layout, the same frame its own login screen uses. It
             * used to render inside the application shell, which showed
             * somebody who had not arrived the furniture of a place they were
             * not in.
             */
            ->assertDontSee('Password')

            /*
             * A Flux tag reaching the browser as text means Blade could not
             * parse the attribute before it and gave up — swallowing the rest
             * of the form, including its submit button. Nested double quotes
             * in an attribute did exactly that, and every other assertion here
             * still passed while the form had no way to be sent.
             */
            ->assertDontSee('<flux:', escape: false);
    }

    public function test_somebody_who_lives_here_is_not_asked_to_type_their_own_address(): void
    {
        $resident = Resident::create([
            'name' => 'Alice',
            'email' => 'alice@games.test',
            'password' => 'irrelevant',
        ]);

        $identity = app(Identities::class)->forResident('alice.games.test')['identity'];
        $identity->owner()->associate($resident)->save();

        $this->actingAs($resident)
            ->get('/connect')
            ->assertOk()
            ->assertSee('value="alice.games.test"', escape: false);
    }

    public function test_a_stranger_is_asked_for_an_address_with_nothing_filled_in(): void
    {
        $this->get('/connect')
            ->assertOk()
            ->assertSee('value=""', escape: false);
    }

    /**
     * A venue houses nobody, so the only honest answer to somebody with no
     * address is the name of a server that does.
     */
    public function test_the_door_says_where_to_get_an_address(): void
    {
        config()->set('streetmesh.venue.domicile', 'stme.sh');

        $this->get('/connect')
            ->assertOk()
            ->assertSee('No address yet?')
            ->assertSee('Create an account')
            ->assertSee('https://stme.sh/register', escape: false);
    }

    /**
     * A recommendation, and one an operator can decline to make.
     */
    public function test_a_venue_that_names_no_domicile_makes_no_offer(): void
    {
        config()->set('streetmesh.venue.domicile', null);

        $this->get('/connect')
            ->assertOk()
            ->assertSee('Continue')
            ->assertDontSee('Create an account');
    }

    /**
     * A server can be both a domicile and a venue. Telling somebody who lives
     * here to go and get an account is telling them to do what they have done.
     */
    public function test_a_resident_is_not_told_to_go_and_get_an_account(): void
    {
        config()->set('streetmesh.venue.domicile', 'stme.sh');

        $resident = Resident::create([
            'name' => 'Alice',
            'email' => 'alice@games.test',
            'password' => 'irrelevant',
        ]);

        $identity = app(Identities::class)->forResident('alice.games.test')['identity'];
        $identity->owner()->associate($resident)->save();

        $this->actingAs($resident)
            ->get('/connect')
            ->assertOk()
            ->assertDontSee('Create an account');
    }

    /**
     * What this venue looks like, at the address every venue publishes it at.
     *
     * A domicile that has never heard of this server builds this from the
     * hostname it already has, so there is nothing to negotiate and nothing to
     * validate. Outside the door, because whoever is asking has never been here
     * and may well decide not to come.
     */
    public function test_a_venue_publishes_its_mark_where_anybody_can_find_it(): void
    {
        config()->set('streetmesh.venue.mark', 'brand/tabletop-mark');

        $this->get('/mark.svg')->assertRedirectContains('brand/tabletop-mark-small.svg');
        $this->get('/mark-dark.svg')->assertRedirectContains('brand/tabletop-mark-dark-small.svg');
    }

    /**
     * And a venue nobody has branded publishes one too, so the convention holds
     * for every venue rather than only the dressed-up ones.
     */
    public function test_an_unbranded_venue_publishes_the_servers_own_mark(): void
    {
        config()->set('streetmesh.venue.mark', null);

        $this->get('/mark.svg')->assertRedirectContains('streetmesh-mark-small.svg');
    }

    public function test_the_published_redirect_is_the_route_that_receives_it(): void
    {
        /*
         * A domicile refuses an authorization request whose redirect is not one
         * the venue's client metadata document lists, and the refusal arrives
         * from somebody else's server — where it reads as their fault. These
         * were two written-down strings in two packages, so renaming this route
         * would have broken every authorization silently.
         */
        // Everything in this document is fetched by strangers, so the protocol
        // refuses to publish anything that is not https.
        URL::forceScheme('https');

        $document = $this->get('/client-metadata.json')->assertOk()->json();

        $this->assertSame([route('venue.callback')], $document['redirect_uris']);
        $this->assertStringEndsWith('/connect/callback', $document['redirect_uris'][0]);
    }

    public function test_arriving_with_nothing_typed_says_so(): void
    {
        $this->post('/connect', ['handle' => '  '])->assertSessionHasErrors(ConnectController::REFUSAL);
    }

    /**
     * Once, not twice.
     *
     * Keyed on the field name, Flux drew the refusal under the input and the
     * form drew it again in a callout — the same sentence, six lines apart.
     * Both were correct on their own, which is why neither looked wrong until
     * somebody saw the screen.
     */
    public function test_a_refusal_is_said_once(): void
    {
        $this->from('/connect')->post('/connect', ['handle' => '  ']);

        $page = (string) $this->get('/connect')->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count((string) $page, 'Type the address you use.'),
            'the same refusal in two places reads as two problems',
        );
    }

    /**
     * A name that resolves to nothing is almost always a typo, and should read
     * as one rather than as whatever the discovery chain threw.
     */
    public function test_an_address_that_answers_to_nobody_is_reported_as_an_address(): void
    {
        $this->post('/connect', ['handle' => 'nobody.example'])
            ->assertSessionHasErrors(ConnectController::REFUSAL);

        $this->assertStringContainsString(
            'nobody.example',
            (string) session('errors')?->first(ConnectController::REFUSAL),
        );
    }

    /**
     * A callback nobody asked for is somebody else's business, and must not
     * seat them.
     */
    public function test_an_answer_to_a_question_nobody_asked_seats_nobody(): void
    {
        $this->get('/connect/callback?state=made-up&code=made-up')
            ->assertRedirect(route('venue.connect'));

        $this->assertNull(session(Visitors::SESSION_KEY));
    }

    /**
     * Cancelling is a decision, and the answer to it is being let back in.
     *
     * This used to return them to the door with "permission was not given",
     * which told somebody what they had just chosen and then asked the same
     * question again — a refusal made to feel like a failed attempt.
     */
    public function test_cancelling_puts_somebody_back_in_the_venue(): void
    {
        $this->get('/connect/callback?error=access_denied')
            ->assertRedirect(route('venue.experiences'))
            ->assertSessionHasNoErrors();

        $this->assertNull(session(Visitors::SESSION_KEY), 'refusing seats nobody');
    }

    /**
     * A destination does not outlive the decision to abandon it. Left in the
     * session it would fire on their next arrival, months later, and send them
     * somewhere they had not asked to go.
     */
    public function test_cancelling_forgets_where_they_were_heading(): void
    {
        session([Visitors::INTENDED_KEY => url('/experiences/chess')]);

        $this->get('/connect/callback?error=access_denied');

        $this->assertNull(session(Visitors::INTENDED_KEY));
    }

    /**
     * Anything that is not somebody choosing is something going wrong, and that
     * still belongs at the door with an explanation.
     */
    public function test_a_failure_is_reported_rather_than_left_silent(): void
    {
        $this->get('/connect/callback?error=server_error')
            ->assertRedirect(route('venue.connect'))
            ->assertSessionHasErrors(ConnectController::REFUSAL);
    }

    /**
     * Two things were being conflated, and only one of them was happening.
     *
     * The authoritative record of a permission lives at their own server, and
     * only they can withdraw it there — that much was right, and is why this
     * cannot promise the grant is gone everywhere.
     *
     * What does not follow is that this venue should keep its copy. The token
     * and refresh token are ours; discarding them is entirely within our power
     * and is the honest answer to somebody saying they are done. It used to
     * forget the session and keep both, so pressing the button ended the visit
     * and revoked nothing, while this venue went on holding everything it
     * needed to write to their repository.
     */
    public function test_leaving_gives_up_the_permission_rather_than_only_the_session(): void
    {
        $delegation = $this->seated();

        /*
         * To the front of the venue, not to the door. Somebody who has just
         * revoked is standing in the building holding nothing, and being shown
         * the way in again reads as being shown out.
         */
        $this->post('/leave')->assertRedirect('/');

        $this->assertNull(session(Visitors::SESSION_KEY));
        $this->assertNull(
            $delegation->fresh(),
            'this venue must not go on holding a token somebody has finished with',
        );
    }

    /**
     * Who is here is answerable, and is answered once.
     *
     * The menu of experiences used to carry a card naming the visitor, which
     * on a server that is both a domicile and a venue put the same person on
     * screen twice — an account in the sidebar and a permission in the body,
     * competing for the same question. It belongs wherever the host already
     * shows who somebody is.
     *
     * So this asserts what the package still owes: the answer is available to
     * whoever is drawing the chrome, and the menu itself does not draw a second
     * one. Restoring the card would put the duplication back.
     */
    public function test_who_is_visiting_is_answerable_without_the_menu_saying_it(): void
    {
        $delegation = $this->seated();

        $this->get('/experiences')
            ->assertOk()
            ->assertDontSee('alice.home.test');

        // Still seated, and still answerable — the menu simply is not the one
        // answering.
        $this->assertSame($delegation->id, session(Visitors::SESSION_KEY));
    }

    /**
     * The chrome shows a visitor as somebody who is here.
     *
     * The corner of every screen used to check `auth()`, decide nobody was
     * there, and offer "Log in" — for an account a venue cannot issue, at a
     * path it should not even serve. A visitor is not signed in; only this
     * package can see them.
     */
    public function test_a_visitor_is_somebody_the_chrome_can_see(): void
    {
        $capability = new VenueCapability;

        $request = Request::create('/experiences');
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);

        $this->assertNull($capability->whoever(), 'nobody is here yet');

        $delegation = $this->seated();

        $this->assertSame(
            [
                'name' => 'alice.home.test',
                'leave' => ['label' => 'Leave', 'route' => 'venue.leave'],
            ],
            $capability->whoever(),
            'a visitor is known by the address their own server issued',
        );

    }

    /**
     * The front page offers the door to somebody outside it, and something
     * else to somebody already through.
     *
     * A visitor is not `@auth` — the framework knows nothing about them — so
     * the page went on offering "Connect" to people standing inside. Only this
     * package can tell the difference, which is why it answers the question.
     */
    public function test_the_front_page_stops_offering_the_door_once_somebody_is_through(): void
    {
        $capability = new VenueCapability;

        // A browser, because whether somebody is here is a question about one.
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);

        $this->assertSame(
            ['label' => 'Connect', 'route' => 'venue.connect'],
            $capability->frontAction(),
            'somebody who has not arrived is offered the way in',
        );

        $this->seated();

        $this->assertSame(
            ['label' => 'Experiences', 'route' => 'venue.experiences'],
            $capability->frontAction(),
            'somebody already here is offered what is on',
        );
    }

    /**
     * And nothing to ask, where there is no browser to ask about.
     *
     * A console command has no session, and the session store throws rather
     * than shrugging. The plain answer is the right one there.
     */
    public function test_the_way_in_is_offered_where_there_is_no_session_at_all(): void
    {
        $this->app->instance('request', Request::create('/'));

        $this->assertSame(
            ['label' => 'Connect', 'route' => 'venue.connect'],
            (new VenueCapability)->frontAction(),
        );
    }

    /**
     * The sequence that broke it, kept as a test rather than a memory.
     *
     * Being sent to the door remembers where somebody was heading. When that
     * key was a dot-notation child of the seating key, writing it replaced the
     * delegation id with an array — unseating whoever was here, and then
     * failing several requests later with a type error from inside Eloquent,
     * nowhere near the cause.
     */
    public function test_being_sent_to_the_door_does_not_unseat_whoever_is_here(): void
    {
        $delegation = $this->seated();

        // A guarded page somebody is entitled to, so the middleware lets them
        // through and does not write an intended URL.
        $this->get('/experiences')->assertOk();

        // And one that does write it, on a session that is already seated.
        session([Visitors::INTENDED_KEY => url('/chess')]);

        $this->assertSame($delegation->id, session(Visitors::SESSION_KEY));

        $this->get('/experiences')->assertOk();
    }

    /**
     * A session holding something unexpected means nobody is here, which is
     * what it should say rather than what Eloquent says about arrays.
     */
    public function test_a_session_holding_nonsense_seats_nobody(): void
    {
        $request = Request::create('/experiences');
        $request->setLaravelSession($this->app['session.store']);

        $request->session()->put(Visitors::SESSION_KEY, ['intended' => 'https://example.test']);

        $this->assertNull($this->app->make(Visitors::class)->current($request));
    }

    /**
     * The gallery is what an installed experience appears in, and the only
     * thing that puts it there is having been registered.
     */
    public function test_an_installed_experience_appears_in_the_gallery(): void
    {
        $this->app->make(Experiences::class)->register(new class implements Experience
        {
            public function name(): string
            {
                return 'com.example.pinball';
            }

            public function title(): string
            {
                return 'Pinball';
            }

            public function description(): string
            {
                return 'Nudge it and it tilts.';
            }

            public function icon(): string
            {
                return 'squares-2x2';
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
             * @return array{label: string, route: string}|null
             */
            public function watching(): ?array
            {
                return null;
            }

            public function audience(Gathering $gathering): Audience
            {
                return Audience::Anybody;
            }

            /**
             * @return array<int, string>
             */
            public function scopes(): array
            {
                return ['repo:com.example.pinball?action=create'];
            }

            public function room(): ?string
            {
                return null;
            }
        });

        $this->seated();

        $this->get('/experiences')
            ->assertOk()
            ->assertSee('Pinball')
            ->assertSee('Nudge it and it tilts.')
            ->assertDontSee('Nothing installed yet');
    }

    /**
     * A venue asks for what it can actually use.
     *
     * Configured separately, this is where a consent screen and an installed
     * package drift apart — somebody agrees to one thing and the venue then
     * fails to write the record it just promised them.
     */
    public function test_what_a_visitor_is_asked_for_comes_from_what_is_installed(): void
    {
        $experiences = $this->app->make(Experiences::class);

        $this->assertSame(['atproto'], $experiences->scopes());

        $experiences->register(new class implements Experience
        {
            public function name(): string
            {
                return 'com.example.pinball';
            }

            public function title(): string
            {
                return 'Pinball';
            }

            public function description(): string
            {
                return '';
            }

            public function icon(): string
            {
                return 'squares-2x2';
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
             * @return array{label: string, route: string}|null
             */
            public function watching(): ?array
            {
                return null;
            }

            public function audience(Gathering $gathering): Audience
            {
                return Audience::Anybody;
            }

            /**
             * @return array<int, string>
             */
            public function scopes(): array
            {
                return ['repo:com.example.pinball?action=create'];
            }

            public function room(): ?string
            {
                return null;
            }
        });

        $this->assertSame(
            ['atproto', 'repo:com.example.pinball?action=create'],
            $experiences->scopes(),
        );
    }

    private function seated(): Delegation
    {
        $delegation = Delegation::create([
            'did' => 'did:web:alice.home.test',
            'handle' => 'alice.home.test',
            'issuer' => 'https://home.test',
            'dpop_key' => Delegation::store(P256::generate()),
            'access_token' => 'a-live-token',
            'scope' => 'atproto',
            'expires_at' => now()->addMinutes(15),
        ]);

        session([Visitors::SESSION_KEY => $delegation->id]);

        return $delegation;
    }
}
