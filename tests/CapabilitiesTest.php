<?php

namespace StreetMesh\Server\Tests;

use PHPUnit\Framework\TestCase as Plain;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Capabilities\Capability;
use StreetMesh\Server\Protocol\Capabilities\Mark;

/**
 * What a server offers, and the operator's say in it.
 *
 * Installing a package is how a capability arrives, and for a server that does
 * one thing that is the whole of the configuration. These are the tests for the
 * case that is not: two servers built from one codebase, installing the same
 * packages, which are not the same server.
 */
final class CapabilitiesTest extends Plain
{
    public function test_what_is_installed_is_offered(): void
    {
        $capabilities = new Capabilities;
        $capabilities->register($this->capability('venue'));

        $this->assertTrue($capabilities->has('venue'));
    }

    /**
     * Unset is not off. A server that says nothing offers what it installed,
     * which is what a development machine and every existing install expect.
     */
    public function test_saying_nothing_leaves_it_on(): void
    {
        $capabilities = new Capabilities(['domicile' => null]);
        $capabilities->register($this->capability('domicile'));

        $this->assertTrue($capabilities->has('domicile'));
    }

    public function test_an_operator_can_switch_one_off(): void
    {
        $capabilities = new Capabilities(['venue' => false]);
        $capabilities->register($this->capability('venue'));

        $this->assertFalse($capabilities->has('venue'));
    }

    /**
     * The arrangement this exists for: one codebase, two servers.
     */
    public function test_one_off_leaves_the_others_alone(): void
    {
        $capabilities = new Capabilities(['venue' => false]);
        $capabilities->register($this->capability('venue'));
        $capabilities->register($this->capability('domicile'));

        $this->assertFalse($capabilities->has('venue'));
        $this->assertTrue($capabilities->has('domicile'));
    }

    /**
     * A switch named for a capability nobody here has heard of works the same
     * way, because the name comes from the capability rather than from a list.
     */
    public function test_a_capability_this_package_never_heard_of_has_a_switch_too(): void
    {
        $capabilities = new Capabilities(['shop' => false]);
        $capabilities->register($this->capability('shop'));

        $this->assertFalse($capabilities->has('shop'));
    }

    private function capability(string $name, bool $greets = false): Capability
    {
        return new class($name, $greets) implements Capability
        {
            public function __construct(
                private readonly string $name,
                private readonly bool $greets,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function serviceType(): string
            {
                return 'Test';
            }

            public function frontPage(): ?string
            {
                return $this->greets ? $this->name.'::front' : null;
            }

            public function frontAction(): ?array
            {
                return null;
            }

            public function whoever(): ?array
            {
                return null;
            }

            public function widgets(): array
            {
                return [];
            }

            public function navigation(): array
            {
                return [];
            }

            public function settings(): array
            {
                return [];
            }

            public function mark(): Mark
            {
                return new Mark('brand/'.$this->name.'-mark');
            }
        };
    }

    /**
     * Two halves of one server, wearing two marks.
     *
     * The whole reason a mark belongs to a capability rather than to the
     * application: a server can be a domicile and a venue at once, and one mark
     * for both would tell somebody reading their own records that they were at
     * the games room.
     */
    /**
     * Settings is a shell no capability owns, so what lands there is offered
     * rather than placed — in the order the capabilities were registered, and
     * with the ones that offer nothing contributing nothing rather than a gap.
     */
    public function test_the_settings_screen_collects_what_capabilities_offer(): void
    {
        $capabilities = new Capabilities;

        $capabilities->register($this->offering('domicile', [
            ['label' => 'Avatars', 'route' => 'domicile.avatar'],
        ]));
        $capabilities->register($this->offering('venue', []));

        $this->assertSame(
            [['label' => 'Avatars', 'route' => 'domicile.avatar']],
            $capabilities->settings(),
        );
    }

    public function test_a_server_where_nobody_offers_anything_has_nothing_to_add(): void
    {
        $capabilities = new Capabilities;

        $capabilities->register($this->offering('venue', []));

        $this->assertSame([], $capabilities->settings());
    }

    /**
     * @param  array<int, array{label: string, route: string, icon?: string}>  $offers
     */
    private function offering(string $name, array $offers): Capability
    {
        return new class($this->capability($name), $offers) implements Capability
        {
            /**
             * @param  array<int, array{label: string, route: string, icon?: string}>  $offers
             */
            public function __construct(private Capability $inner, private array $offers) {}

            public function settings(): array
            {
                return $this->offers;
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function serviceType(): string
            {
                return $this->inner->serviceType();
            }

            public function frontPage(): ?string
            {
                return $this->inner->frontPage();
            }

            public function frontAction(): ?array
            {
                return $this->inner->frontAction();
            }

            public function whoever(): ?array
            {
                return $this->inner->whoever();
            }

            public function widgets(): array
            {
                return $this->inner->widgets();
            }

            public function navigation(): array
            {
                return $this->inner->navigation();
            }

            public function mark(): Mark
            {
                return $this->inner->mark();
            }
        };
    }

    public function test_each_capability_answers_with_its_own_mark(): void
    {
        $capabilities = new Capabilities;

        $capabilities->register($this->capability('venue'));
        $capabilities->register($this->capability('domicile'));

        $this->assertStringContainsString('venue-mark-small.svg', $capabilities->mark('venue')->light());
        $this->assertStringContainsString('domicile-mark-small.svg', $capabilities->mark('domicile')->light());
    }

    /**
     * The pair from one name, because a mark that carries its own ground needs
     * a second drawing and two configured paths could disagree.
     */
    public function test_a_mark_names_its_own_dark_drawing(): void
    {
        $mark = new Mark('brand/tabletop-mark');

        $this->assertStringContainsString('tabletop-mark-small.svg', $mark->light());
        $this->assertStringContainsString('tabletop-mark-dark-small.svg', $mark->dark());
    }

    /**
     * Shared chrome asks for nothing in particular, and a screen must not fail
     * to render because a package was removed from under it.
     */
    public function test_the_server_has_a_mark_of_its_own(): void
    {
        $capabilities = new Capabilities;

        $this->assertStringContainsString('streetmesh-mark-small.svg', $capabilities->mark()->light());
        $this->assertStringContainsString('streetmesh-mark-small.svg', $capabilities->mark('absent')->light());
    }

    /**
     * The chrome belongs to no capability in particular, so it wears the mark
     * of whichever one greets people — a sidebar is drawn around whatever
     * screen you are on, and a server that is only a venue is that venue
     * everywhere, not on the two screens somebody remembered to label.
     */
    public function test_the_chrome_wears_the_mark_of_whoever_greets_people(): void
    {
        $capabilities = new Capabilities;
        $capabilities->register($this->capability(greets: true, name: 'venue'));

        $this->assertStringContainsString('venue-mark-small.svg', $capabilities->mark()->light());
    }

    /**
     * And on a server that is more than one thing, the operator has already
     * said which greets strangers. There is no second question to ask them.
     */
    public function test_a_blended_server_follows_the_operators_preference(): void
    {
        $capabilities = new Capabilities;
        $capabilities->register($this->capability(greets: true, name: 'domicile'));
        $capabilities->register($this->capability(greets: true, name: 'venue'));

        $this->assertStringContainsString('venue-mark-small.svg', $capabilities->mark(null, 'venue')->light());
        $this->assertStringContainsString('domicile-mark-small.svg', $capabilities->mark(null, 'domicile')->light());
    }
}
