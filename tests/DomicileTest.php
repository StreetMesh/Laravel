<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Server\Protocol\Capabilities\Capabilities;

class DomicileTest extends TestCase
{
    /**
     * A server that houses people and does not gather them.
     *
     * See the same note on `VenueTest`: which half a server offers stopped
     * being a fact about what was installed and became a line in a config file,
     * so a test about one half alone now says which.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->livesHere($app);

        $app['config']->set('streetmesh.capabilities.venue', false);
    }

    private function capabilities(): Capabilities
    {
        return $this->app->make(Capabilities::class);
    }

    public function test_a_server_that_offers_only_this_says_it_hosts_residents(): void
    {
        $this->assertTrue($this->capabilities()->has('domicile'));
        $this->assertSame(['domicile'], $this->capabilities()->names());
    }

    /**
     * The wire and the interface read one list, so they cannot come to disagree
     * about what this server does.
     */
    public function test_the_did_document_says_so_too(): void
    {
        $document = $this->get('/.well-known/did.json')->assertOk()->json();

        $this->assertSame('AtprotoPersonalDataServer', $document['service'][0]['type']);
    }

    public function test_it_offers_a_front_page_without_claiming_the_root(): void
    {
        $this->assertSame('domicile::front', $this->capabilities()->get('domicile')->frontPage());

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
        $this->assertSame('domicile.records', $widgets[0]->name());
    }

    /**
     * Packages get installed and removed. A page must not fail to render
     * because a configuration file still names one that left.
     */
    public function test_an_arrangement_naming_nothing_is_skipped_rather_than_fatal(): void
    {
        $this->assertSame([], $this->capabilities()->widgets(['a.package.that.left']));
        $this->assertCount(1, $this->capabilities()->widgets(['domicile.records', 'gone']));
    }

    public function test_its_own_screen_is_at_its_own_name(): void
    {
        $this->get('/directory')
            ->assertOk()
            ->assertSee('Nobody lives here yet');
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
            $this->app->make('livewire.finder')->resolveSingleFileComponentPath('domicile::directory')
        );

        $this->get('/directory')->assertSee('wire:model.live.debounce="search"', escape: false);
    }
}
