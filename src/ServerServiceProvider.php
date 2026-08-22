<?php

namespace StreetMesh\Server;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;
use StreetMesh\Protocol\Handle;
use StreetMesh\Protocol\MerkleSearchTree;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Protocol\RecordTree;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Domicile\DomicileCapability;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Console\CheckIdentity;
use StreetMesh\Server\Protocol\Http\LaravelNetwork;
use StreetMesh\Server\Protocol\Identity\DidResolver;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Permissions\Delegations;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use StreetMesh\Server\Protocol\Permissions\Spent;
use StreetMesh\Server\Protocol\Permissions\Tickets;
use StreetMesh\Server\Protocol\Plc\ImportIdentities;
use StreetMesh\Server\Protocol\Records\Collections;
use StreetMesh\Server\Protocol\Records\CommitLog;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\Protocol\Records\RecordStore;
use StreetMesh\Server\Venue\Console\BuildHub;
use StreetMesh\Server\Venue\Console\DeployHub;
use StreetMesh\Server\Venue\Console\MakeExperience;
use StreetMesh\Server\Venue\Console\TidyGatherings;
use StreetMesh\Server\Venue\Console\TidyParties;
use StreetMesh\Server\Venue\Readiness;
use StreetMesh\Server\Venue\VenueCapability;

/**
 * What turns a Laravel application into a StreetMesh server.
 *
 * Three things arrive together and are registered in one order, deliberately.
 * The **protocol** is what everything else stands on: identity, records, blobs,
 * permission. A **domicile** is where people live. A **venue** is where they
 * gather. A server may offer either of the last two, both, or neither.
 *
 * This was three packages and three providers, and the order they booted in was
 * whatever Composer's resolution happened to produce. The code worked around
 * that in several places — asking `offers()` rather than `has()`, deferring a
 * config write to `boot` — and those workarounds were load-bearing precisely
 * because nothing pinned the order. Now something does. The workarounds stay
 * anyway, and each one says below why it is still right for a different reason
 * than the one it was written for.
 */
class ServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerProtocol();
        $this->registerDomicile();
        $this->registerVenue();
    }

    public function boot(): void
    {
        $this->bootProtocol();
        $this->bootDomicile();
        $this->bootVenue();
    }

    // ── The protocol ────────────────────────────────────────────────────────

    private function registerProtocol(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/streetmesh.php', 'streetmesh');

        $this->app->singleton(Network::class, fn (): Network => new LaravelNetwork(
            timeoutSeconds: (int) config('streetmesh.network.timeout', 10),
            cacheSeconds: (int) config('streetmesh.network.cache_seconds', 300),
        ));

        $this->app->singleton(Collections::class, fn (): Collections => new Collections(
            (array) config('streetmesh.collections', []),
        ));

        $this->app->singleton(RecordStore::class);
        $this->app->singleton(BlobStore::class);

        /*
         * The tree other people's software reads. A stranger holding the same
         * records computes the same root, so a commit is a claim anybody can
         * check rather than one only this server can.
         *
         * Bound rather than hard-wired because it was FlatTree until this line
         * changed, and because a server with reason to prefer something else
         * should not have to fork the package to say so.
         */
        $this->app->singleton(RecordTree::class, MerkleSearchTree::class);
        $this->app->singleton(CommitLog::class);

        $this->app->singleton(PlcDirectory::class, fn ($app): PlcDirectory => new PlcDirectory(
            $app->make(Network::class),
            (string) config('streetmesh.plc.directory', PlcDirectory::DEFAULT),
        ));

        $this->app->singleton(Handle::class, fn ($app): Handle => new Handle(
            $app->make(Network::class),
        ));

        $this->app->singleton(DidResolver::class);

        $this->app->singleton(Capabilities::class, fn ($app): Capabilities => new Capabilities(
            (array) config('streetmesh.capabilities', []),
        ));

        $this->app->singleton(Identities::class, fn ($app): Identities => new Identities(
            directory: $app->make(PlcDirectory::class),
            host: (string) config('streetmesh.host', 'localhost'),
            defaultCurve: (string) config('streetmesh.curve', 'p256'),
        ));
        $this->app->singleton(Attestations::class);

        $this->app->singleton(Spent::class);
        $this->app->singleton(Permissions::class);
        $this->app->singleton(Tickets::class);
        $this->app->singleton(Delegations::class);
    }

    private function bootProtocol(): void
    {
        /*
         * Once, for all of them. These were three directories and are now one,
         * which changes nothing about what runs: Laravel sorts the union of
         * every registered path by filename, so the order was already global
         * and merging preserved it exactly. Not one filename moved, because
         * every one of them is a row in somebody's `migrations` table.
         */
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /*
         * Throttled but not authenticated. These answer who this server is, and
         * a record checkable only by somebody with an account here would be as
         * durable as the arrangement between two parties rather than outliving
         * it.
         */
        $this->app['router']->middlewareGroup('streetmesh', ['throttle:120,1']);

        $this->loadRoutesFrom(__DIR__.'/../routes/protocol.php');

        /*
         * The consent screen, and nothing else. Registered as a namespace so an
         * interface package can put its own `consent` view in front of this
         * one — what a resident sees is a domicile's business, and this is here
         * only so a bare server can still ask somebody a question.
         */
        $this->loadViewsFrom(__DIR__.'/../resources/views/streetmesh', 'streetmesh');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckIdentity::class, ImportIdentities::class]);
        }

        $this->publishes([
            __DIR__.'/../config/streetmesh.php' => config_path('streetmesh.php'),
        ], 'streetmesh-config');

        $this->protectSignedDocuments();
    }

    // ── Somewhere people live ───────────────────────────────────────────────

    private function registerDomicile(): void
    {
        /*
         * Merged under the protocol's own key rather than a second root, so an
         * operator configuring a server reads one file rather than one per
         * thing the server does.
         */
        $this->mergeConfigFrom(__DIR__.'/../config/domicile.php', 'streetmesh.domicile');
    }

    private function bootDomicile(): void
    {
        $this->app->make(Capabilities::class)->register(new DomicileCapability);

        $this->declareAvatars();

        $this->loadViewsFrom(__DIR__.'/../resources/views/domicile', 'domicile');

        /*
         * Livewire keeps its own register of namespaces, separate from Blade's.
         * `loadViewsFrom` above is what makes `domicile::front` resolvable as a
         * view; it does nothing for `<livewire:domicile::directory />`, because
         * Livewire's finder consults only what `addNamespace` gave it. Both are
         * needed, and this is exactly how Livewire registers its own `pages`
         * and `layouts` namespaces on boot.
         *
         * No ⚡ in the filename on purpose — an emoji in a path that Composer
         * has to install is a problem nobody needs.
         */
        Livewire::addNamespace('domicile', viewPath: __DIR__.'/../resources/views/domicile/livewire');

        /*
         * A person who typed a resident's address into a browser is asking
         * about a person, not resolving a handle. See the middleware.
         *
         * Outside the switch below, because a resident's hostname keeps meaning
         * something whether or not this server is currently offering the
         * screens that hostname redirects to.
         */
        $this->app['router']->pushMiddlewareToGroup('web', Domicile\Http\SendResidentsHome::class);

        /*
         * Switched off means gone, not hidden. A capability this server does
         * not offer has no screens here — otherwise a venue serves a directory
         * of residents it does not have, at a path a domicile in the same
         * container would want.
         *
         * `offers()` rather than `has()`: the first reads the operator's switch
         * and the second reads the registry. They agree by the time this line
         * runs, and asking the switch is still the honest question — what is
         * being decided is whether the operator wants these screens, not
         * whether the code for them was loaded.
         *
         * Returning here leaves the venue below untouched, which is why these
         * are three methods rather than one.
         */
        if (! $this->app->make(Capabilities::class)->offers('domicile')) {
            return;
        }

        $this->app['router']
            ->middleware('web')
            ->group(__DIR__.'/../routes/domicile.php');

        /*
         * And what a resident's own hostname serves, which is not a browser
         * route and must not be registered as one. See the file.
         *
         * After the browser routes, and that ordering is load-bearing: this
         * file claims `avatar`, and the one above claims `settings/avatar`.
         * Laravel replaces a route sharing a path rather than complaining, and
         * the two named the same thing once left a screen answering 404 with
         * nothing anywhere saying why.
         */
        $this->loadRoutesFrom(__DIR__.'/../routes/domicile-published.php');
    }

    /**
     * Say that this server publishes what its residents look like.
     *
     * In `boot` rather than `register`, and that is not tidiness. `Collections`
     * is bound as a closure that reads this config the first time somebody
     * writes or reads a record, which never happens while providers are being
     * registered — so booting is late enough to be certain everything has had
     * its say, and early enough that nothing has asked yet.
     *
     * The original reason was that nothing guaranteed this ran after the
     * protocol's provider. Now something does, and it would be easy to read
     * that as permission to move this into `register`. It is not: the laziness
     * of the binding is what makes the timing safe, and that has not changed.
     *
     * Only if nobody has already answered. Visibility is what a server
     * publishes, which is the operator's sentence to write; an operator who has
     * said these are private meant it, and what they get is residents whose
     * permalink answers with nothing rather than a setting quietly overruled.
     */
    private function declareAvatars(): void
    {
        /** @var array<string, string> $declared */
        $declared = (array) config('streetmesh.collections', []);

        if (array_key_exists(Avatars::COLLECTION, $declared)) {
            return;
        }

        $declared[Avatars::COLLECTION] = Record::PUBLIC;

        config(['streetmesh.collections' => $declared]);
    }

    // ── Somewhere people gather ─────────────────────────────────────────────

    private function registerVenue(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/venue.php', 'streetmesh.venue');

        $this->app->singleton(Venue\Visitors::class);
        $this->app->singleton(Venue\Experiences\Experiences::class);
        $this->app->singleton(Venue\Gatherings\Gatherings::class);
        $this->app->singleton(Venue\Gatherings\Results::class);
        $this->app->singleton(Venue\Parties\Parties::class);
        $this->app->singleton(Venue\Chat\Chat::class);
        $this->app->singleton(Venue\Media\Mailbox::class);
        $this->app->singleton(Venue\Comms::class);
        $this->app->singleton(Venue\Realtime\Secrets::class);
        $this->app->singleton(Venue\Realtime\Occupancy::class);
    }

    private function bootVenue(): void
    {
        $this->app->make(Capabilities::class)->register(new VenueCapability);

        $this->refuseUnlessEquipped();
        $this->protectSessionDescriptions();

        if ($this->app->runningInConsole()) {
            $this->commands([BuildHub::class, DeployHub::class, MakeExperience::class, TidyGatherings::class, TidyParties::class]);
        }

        /*
         * Scheduled by the package rather than left to an operator to wire up.
         * Tables nobody came to are a consequence of running a venue at all, so
         * clearing them is part of being one — and the failure mode of
         * forgetting is a lobby that fills with invitations nobody can accept.
         *
         * Every five minutes, against a ten-minute wait: often enough that a
         * lobby stays honest, rarely enough that the hub is barely asked.
         */
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(TidyGatherings::class)->everyFiveMinutes()->withoutOverlapping();

            /*
             * The same cadence, and a much cheaper sweep — this one asks the
             * database whether anybody is still a member and never troubles the
             * hub. See the command for why a party can empty without anybody
             * having left.
             */
            $schedule->command(TidyParties::class)->everyFiveMinutes()->withoutOverlapping();
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views/venue', 'venue');

        /*
         * A second Livewire namespace, for the reason given against the first.
         *
         * Both halves keep their own view namespace rather than sharing one,
         * and that is not inertia: `front.blade.php` and `widget.blade.php`
         * exist in both, distinguished by nothing but the namespace. Flattening
         * them would leave a server's front page describing the wrong half of
         * itself, silently.
         */
        Livewire::addNamespace('venue', viewPath: __DIR__.'/../resources/views/venue/livewire');

        /*
         * "Is somebody here", which is not the same question as "is somebody
         * signed in". A venue has no accounts, so the framework's own guards
         * have nothing to check — what this asks is whether this browser is
         * acting under permission somebody's own server gave us.
         */
        $this->app['router']->aliasMiddleware('visitor', Venue\Http\RequireVisitor::class);

        /*
         * Whether the menu is anybody's business, asked per request rather than
         * when routes are registered — a setting consulted at boot gets baked
         * into a cached route table and appears to do nothing.
         */
        $this->app['router']->aliasMiddleware('venue.menu', Venue\Http\GuardTheMenu::class);

        /*
         * Whether this venue does parties at all, asked the same way and for
         * the same reason as the line above it.
         */
        $this->app['router']->aliasMiddleware('parties', Venue\Http\RequireParties::class);

        /*
         * Server to server, so none of the browser middleware. See the file.
         *
         * Registered before the switch below, and deliberately: a hub may still
         * be finishing something that was started before an operator turned the
         * venue off, and refusing to hear about it would lose a game rather
         * than close a door.
         */
        $this->app['router']->middleware([])->group(__DIR__.'/../routes/venue-realtime.php');

        /*
         * Switched off means gone, not hidden — the same rule the domicile
         * follows. A server that is not a venue has no door to one, and no
         * menu of things to do at it.
         */
        if (! $this->app->make(Capabilities::class)->offers('venue')) {
            return;
        }

        $this->app['router']
            ->middleware('web')
            ->group(__DIR__.'/../routes/venue.php');
    }

    // ── Two guarantees about bytes, which stay two ──────────────────────────

    /**
     * Stop the framework tidying anything that carries a signature.
     *
     * Laravel blanks and trims request input as a kindness to HTML forms.
     * Applied to a signed document it is corruption: a signature covers bytes,
     * so turning an empty string into null changes what is being verified and
     * the check fails for a document that was never wrong. The failure looks
     * exactly like forgery, and it is data-dependent, so it appears
     * intermittent.
     *
     * This is the guarantee that most justifies the package existing. Every
     * implementor would otherwise have to know about it, and would find out the
     * same way — by losing two days.
     */
    private function protectSignedDocuments(): void
    {
        $carriesSignature = static fn (Request $request): bool => $request->is(
            ...(array) config('streetmesh.signed_paths', [
                'xrpc/*',
                'records', '*/records',
                'did.json', '*/did.json',
                '.well-known/*', '*/.well-known/*',
            ]),
        );

        ConvertEmptyStringsToNull::skipWhen($carriesSignature);
        TrimStrings::skipWhen($carriesSignature);
    }

    /**
     * Keep the framework's tidying away from a session description.
     *
     * An SDP is not a form field: it is a line-oriented document whose every
     * line ends in CRLF, including the last one. Trimming takes that terminator
     * off and what arrives is a document the far side refuses with "Invalid SDP
     * line" — after which every ICE candidate for it fails with "the remote
     * description was null", because there is no description to attach them to.
     *
     * **A second call, and it has to stay a second call.** `skipWhen` appends
     * rather than replaces, so these two predicates both run and either one can
     * spare a request. Folding them into one registration with a combined path
     * list would work today and would couple two rules that are true for
     * unrelated reasons — a signalling route is not a signed one, and the day
     * one list changes is the day somebody has to work out which half of a
     * merged predicate they are allowed to touch.
     */
    private function protectSessionDescriptions(): void
    {
        $carriesDescription = static fn (Request $request): bool => $request->is('parties/*/signals');

        ConvertEmptyStringsToNull::skipWhen($carriesDescription);
        TrimStrings::skipWhen($carriesDescription);
    }

    /**
     * A venue that cannot do the job does not open.
     *
     * What counts as equipped is decided by `Readiness`; this only asks, and
     * turns an answer into a stop.
     *
     * Not in the console, which has to keep working — `key:generate` and
     * `migrate` are how a server gets to the point of having either of the
     * things being asked about. `composer install` runs `package:discover`,
     * which boots the application, so without this guard installing the package
     * would fail on a server that has not been configured yet.
     */
    private function refuseUnlessEquipped(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $readiness = new Readiness(
            isVenue: $this->app->make(Capabilities::class)->has('venue'),
            hasSecret: $this->app->make(Venue\Realtime\Secrets::class)->configured(),
            hub: config('streetmesh.venue.hub'),
            parties: (bool) config('streetmesh.venue.parties.enabled', false),
            partySize: (int) config('streetmesh.venue.parties.size', 0),
        );

        $missing = $readiness->missing();

        if ($missing !== null) {
            throw new RuntimeException($missing);
        }

        /*
         * Said rather than thrown. These are settings that will work and not the
         * way somebody asked for, which is not a reason to stay shut — but a
         * venue that silently did something other than what its configuration
         * says is exactly the failure this class exists to prevent.
         */
        foreach ($readiness->concerns() as $concern) {
            Log::warning($concern);
        }
    }
}
