<?php

namespace StreetMesh\Server\Tests;

use Flux\FluxServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use StreetMesh\Protocol\Network;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\ServerServiceProvider;

/**
 * One base for the whole package.
 *
 * There were three, one per package, and they disagreed in ways that were
 * invisible while they never ran together: two called this server `games.test`
 * and one called it `home.test`; two enforced foreign keys and one did not.
 * Merging them meant picking, and the picks are recorded here rather than left
 * to be rediscovered.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /*
         * Livewire and Flux are listed because this package ships screens
         * written in them, and testbench boots only what it is told about.
         */
        return [
            LivewireServiceProvider::class,
            FluxServiceProvider::class,
            ServerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /*
         * Nothing here talks to the outside world.
         *
         * A default rather than something each test opts into, because the
         * tests that reach the network by accident are exactly the ones that
         * did not think they would. A test wanting particular answers binds its
         * own.
         */
        $app->instance(Network::class, new OfflineNetwork);

        // Keys are encrypted at rest, so a server without an application key
        // cannot hold an identity at all. Worth failing loudly in tests rather
        // than discovering it on a first deploy.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');

        /*
         * The same referential integrity the real database has.
         *
         * SQLite leaves foreign keys off unless asked, so a delete that
         * cascades in production quietly leaves the child rows behind here.
         * A seat outliving the permission it belongs to is not a shape any
         * server ever sees, and testing against it hid a crash in the lobby
         * for a state that only arises when somebody revokes.
         *
         * The domicile's suite ran without this and now runs with it, which is
         * the stricter of the two answers and the one that matches production.
         */
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        /*
         * This server is `games.test`, and the people who visit it live at
         * `*.home.test`.
         *
         * The domicile's suite used to set the host to `home.test`, because its
         * subject is residents of *this* server. Both readings are legitimate
         * and only one can be the default, so a test about somebody who lives
         * here says so for itself — see `livesHere()`.
         */
        $app['config']->set('streetmesh.host', 'games.test');

        /*
         * Testbench answers on http://localhost, and this package refuses to
         * publish a document naming anything reachable in the clear. That rule
         * is not relaxed for tests: a real deployment is served over TLS — Herd
         * provides it locally, and anywhere worth deploying to provides it in
         * production — so a test environment on plain http would be exercising
         * a situation that never occurs while hiding the check that matters.
         */
        $app['config']->set('app.url', 'https://games.test');
        $app['url']->forceRootUrl('https://games.test');
        $app['url']->forceScheme('https');

        $app['config']->set('streetmesh.collections', [
            'com.streetmesh.games.chess' => Record::PUBLIC,
            'com.streetmesh.messages.direct' => Record::PRIVATE,
        ]);

        /* A venue refuses to boot without somewhere live to point at. */
        $app['config']->set('streetmesh.venue.hub', 'wss://hub.invalid');
        $app['config']->set('streetmesh.venue.secret', 'a-secret-shared-with-the-hub');

        /*
         * Stand-ins for the host application's chrome.
         *
         * This package ships screens written against the Livewire starter kit's
         * layout, which is the opinion the project settled on — so it cannot
         * render one of its own screens without a host. Pointing the namespaces
         * at stubs is what Livewire itself does with the real ones on boot.
         *
         * Through Livewire's config rather than by hand: it turns each of these
         * into a view namespace *and* an anonymous component path, and only the
         * second makes `<x-pages::…>` resolve.
         */
        $app['config']->set('livewire.component_namespaces.layouts', __DIR__.'/Fixtures/views/layouts');
        $app['config']->set('livewire.component_namespaces.pages', __DIR__.'/Fixtures/views/pages');

        /* The settings heading is an ordinary include, so it wants a path. */
        $app['config']->set('view.paths', [
            ...(array) $app['config']->get('view.paths', []),
            __DIR__.'/Fixtures/views',
        ]);
    }

    /**
     * This server is the one people live on, for the length of one test.
     *
     * Call it from `defineEnvironment` in a test whose subject is a resident
     * rather than a visitor. Both halves of a server are exercised by this one
     * suite and they mean opposite things by "here".
     */
    protected function livesHere(Application $app, string $host = 'home.test'): void
    {
        $app['config']->set('streetmesh.host', $host);
    }

    /**
     * A stand-in for the host's front door.
     *
     * This package has always assumed the application provides one —
     * `DomicileCapability::frontAction` sends people to a route named `login`
     * and nothing here defines it. Screens behind `auth` make that assumption
     * load-bearing.
     */
    protected function defineRoutes($router): void
    {
        $router->get('login', fn () => 'the door')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        /*
         * A domicile joins an account to an address, so these tests need both
         * halves: the framework's users and this package's own tables.
         */
        $this->loadLaravelMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
