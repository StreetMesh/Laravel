<?php

namespace StreetMesh\Server\Venue;

use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Capabilities\Capability;
use StreetMesh\Server\Protocol\Capabilities\Mark;
use StreetMesh\Server\Protocol\Capabilities\Widget;

/**
 * This server is somewhere people gather.
 *
 * It says so on the wire, offers something to greet strangers with, and offers
 * a panel for a signed-in person's home page. It does not decide where any of
 * that goes, because a server may offer more than one capability and only the
 * application can arrange them.
 */
final class VenueCapability implements Capability
{
    public function name(): string
    {
        return 'venue';
    }

    public function serviceType(): string
    {
        return 'StreetMeshVenue';
    }

    public function frontPage(): string
    {
        return 'venue::front';
    }

    /**
     * Arriving, which is not signing in — unless they already have.
     *
     * A venue holds no accounts. Somebody turns up with an address issued by
     * their own server and asks that server for permission, so the way in is a
     * box to type an address into; a login form would be a key to a lock this
     * server does not have.
     *
     * Somebody who has already arrived is a **visitor**, which is not the same
     * as being signed in — the framework's own `@auth` knows nothing about
     * them, so the front page went on offering the door to people standing
     * inside it. Only this package knows the difference, which is why the
     * question is answered here rather than in the page.
     *
     * @return array{label: string, route: string}
     */
    public function frontAction(): array
    {
        /*
         * Only where there is a session to ask about. Whether somebody is here
         * is a question about this browser, and a console command or anything
         * else without one is not a browser — it should get the plain answer
         * rather than an exception from inside the session store.
         */
        $arrived = request()->hasSession()
            && app(Visitors::class)->current(request()) !== null;

        return $arrived
            ? ['label' => 'Experiences', 'route' => 'venue.experiences']
            : ['label' => 'Connect', 'route' => 'venue.connect'];
    }

    /**
     * A visitor: somebody holding permission from another server.
     *
     * Their own address, because that is who they are here — this server issued
     * them nothing and knows them by no other name.
     *
     * @return null|array{name: string, leave: array{label: string, route: string}}
     */
    public function whoever(): ?array
    {
        if (! request()->hasSession()) {
            return null;
        }

        $visitor = app(Visitors::class)->current(request());

        return $visitor === null ? null : [
            'name' => (string) $visitor->handle,
            'leave' => ['label' => 'Leave', 'route' => 'venue.leave'],
        ];
    }

    /**
     * @return array<int, Widget>
     */
    public function widgets(): array
    {
        return [new VenueWidget];
    }

    /**
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function navigation(): array
    {
        return [
            ['label' => 'Experiences', 'route' => 'venue.experiences'],
        ];
    }

    /**
     * Nothing, and that is not an oversight.
     *
     * A venue holds no accounts, so a visitor has nothing here to configure —
     * what they look like and who they are belong to the server they came from,
     * and are settings there rather than settings here. See `whoever`, which
     * makes the same distinction about the person.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * What this venue is called, in pictures.
     *
     * The venue is the half of a server strangers meet, and the half most
     * likely to be a thing with a name of its own — Tabletop runs on StreetMesh
     * the way a shop stands on a high street. A domicile in the same container
     * is not that thing and must not wear its mark.
     *
     * Falls back to the server's own, so a venue nobody has branded looks the
     * way it always did.
     *
     * `?:` rather than a default argument, because the key is always present —
     * the config file declares it, unset or not. `config($key, $default)` never
     * reaches its default here, and an unset variable drew a mark called
     * `-small.svg`.
     */
    public function mark(): Mark
    {
        return new Mark((string) (config('streetmesh.venue.mark') ?: Capabilities::OWN_MARK));
    }
}
