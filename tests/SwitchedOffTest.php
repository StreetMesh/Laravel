<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Server\Protocol\Capabilities\Capabilities;

/**
 * A server that installs this package and is not a venue.
 *
 * The arrangement this exists for: Domiciles and Tabletop are built from one
 * codebase and install the same packages, so Composer cannot be what decides
 * which is which. `Readiness` proves what the decision should be; this proves
 * the operator's answer actually reaches the registry.
 */
final class SwitchedOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.capabilities.venue', false);
    }

    public function test_the_venue_is_not_offered(): void
    {
        $this->assertFalse($this->app->make(Capabilities::class)->has('venue'));
    }

    /**
     * And nothing downstream is asked to branch on what kind of server this is
     * — a stranger reading the DID document simply finds no venue in it.
     */
    public function test_it_is_not_announced(): void
    {
        $offered = array_map(
            fn ($capability): string => $capability->name(),
            $this->app->make(Capabilities::class)->all(),
        );

        $this->assertNotContains('venue', $offered);
    }
}
