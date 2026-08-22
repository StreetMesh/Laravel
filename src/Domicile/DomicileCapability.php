<?php

namespace StreetMesh\Server\Domicile;

use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Capabilities\Capability;
use StreetMesh\Server\Protocol\Capabilities\Mark;
use StreetMesh\Server\Protocol\Capabilities\Widget;
use StreetMesh\Server\Protocol\Identity\Identities;

/**
 * This server is somewhere people live.
 *
 * It says so on the wire, offers something to greet strangers with, and offers
 * a panel for a signed-in person's home page. It does not decide where any of
 * that goes, because a server may offer more than one capability and only the
 * application can arrange them.
 */
final class DomicileCapability implements Capability
{
    public function name(): string
    {
        return 'domicile';
    }

    public function serviceType(): string
    {
        return 'AtprotoPersonalDataServer';
    }

    public function frontPage(): string
    {
        return 'domicile::front';
    }

    /**
     * @return array{label: string, route: string}
     */
    public function frontAction(): array
    {
        return ['label' => 'Sign in', 'route' => 'login'];
    }

    /**
     * A resident: an account here, and a session the framework understands.
     *
     * @return null|array{name: string, leave: array{label: string, route: string}}
     */
    public function whoever(): ?array
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return [
            /*
             * `name` is asked for by attribute rather than as a property,
             * because this package cannot see the host's user model and the
             * framework's base class declares no such thing. It is a fallback
             * for somebody who has an account here but no address yet.
             */
            'name' => (string) (app(Identities::class)->forUser($user)->handle ?? $user->getAttribute('name')),
            'leave' => ['label' => 'Log out', 'route' => 'logout'],
        ];
    }

    /**
     * @return array<int, Widget>
     */
    public function widgets(): array
    {
        return [new DomicileWidget];
    }

    /**
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function navigation(): array
    {
        return [
            ['label' => 'Directory', 'route' => 'domicile.directory', 'icon' => 'user-group'],
        ];
    }

    /**
     * The first thing a resident of this server gets to decide about themselves.
     *
     * In settings rather than on the home page, because that is where a person
     * looks for something about themselves rather than something to do — and
     * because the home page is a summary, not somewhere things happen.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function settings(): array
    {
        return [
            ['label' => 'Avatars', 'route' => 'domicile.avatar', 'icon' => 'user-circle'],
        ];
    }

    /**
     * What this domicile is called, in pictures.
     *
     * Separately settable from the venue's and rarely set. A domicile is the
     * half that holds somebody's identity and their records, and the thing a
     * person wants to recognise there is the server they chose to live on —
     * which is usually the plain one the operator runs under, whatever the
     * venue in the same container has been dressed up as.
     */
    public function mark(): Mark
    {
        return new Mark((string) (config('streetmesh.domicile.mark') ?: Capabilities::OWN_MARK));
    }
}
