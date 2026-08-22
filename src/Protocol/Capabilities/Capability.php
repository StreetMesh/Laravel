<?php

namespace StreetMesh\Server\Protocol\Capabilities;

/**
 * Something a server offers.
 *
 * Domicile and venue are capabilities rather than kinds of server. A server may
 * host residents, host things people do together, both, or neither, and nothing
 * downstream should branch on what kind of server it is talking to — only on
 * what that server offers.
 *
 * The awkward consequence, and the reason this exists: two capabilities
 * installed side by side both want the front page, both want to be in the
 * navigation, and both want to say what this server is. None of that can be
 * settled by either of them, because neither outranks the other. So each
 * declares what it provides and the application decides where it goes.
 */
interface Capability
{
    /**
     * What this capability is called, on the wire and in configuration.
     */
    public function name(): string;

    /**
     * How a DID document names the service this capability provides, so that a
     * stranger reading it learns what this server does without being told.
     */
    public function serviceType(): string;

    /**
     * What a stranger sees if the server puts this capability at its root.
     *
     * A view rather than a route, because there is only one root and only the
     * application can say which capability gets it. Null means this capability
     * has nothing to say to somebody who has not arrived yet.
     */
    public function frontPage(): ?string;

    /**
     * The way in, offered on the front page beneath whatever that page says.
     *
     * Declared here because it is not the same door everywhere and the
     * application cannot know which one it is. A domicile signs somebody in: it
     * holds accounts, and the person arriving has one. A venue holds no
     * accounts at all — somebody arrives with an address from another server —
     * so sending them to a login form is offering them a key to a lock that
     * does not exist.
     *
     * Null for a capability with no front door of its own.
     *
     * @return null|array{label: string, route: string}
     */
    public function frontAction(): ?array;

    /**
     * Whoever is here, as this capability knows them — or nobody.
     *
     * The two capabilities mean different things by "here" and neither can be
     * deduced from the other. A domicile has a **resident**: an account, a
     * password, a session the framework understands. A venue has a **visitor**:
     * somebody holding permission from another server, who the framework's own
     * `auth()` knows nothing about at all.
     *
     * Asked because the chrome cannot tell. A layout that checks `auth()` shows
     * a venue's visitors a "Log in" link for accounts that server does not
     * have, next to screens they are already using.
     *
     * `leave` is the way out, and is a POST — signing out and giving up a seat
     * both change something, and neither should happen because a link was
     * prefetched.
     *
     * @return null|array{name: string, leave: array{label: string, route: string}}
     */
    public function whoever(): ?array;

    /**
     * Panels this capability offers for a signed-in person's home page.
     *
     * Offered rather than placed. The home page is the one surface where two
     * installed capabilities genuinely overlap, and the operator arranges it.
     *
     * @return array<int, Widget>
     */
    public function widgets(): array;

    /**
     * What this capability contributes to a shell it does not control.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function navigation(): array;

    /**
     * And what it contributes to the settings shell, which is a different one.
     *
     * Separate from `navigation` because the two answer different questions.
     * Navigation is where somebody goes to do something here. Settings is where
     * they go to change something about themselves — and a capability that adds
     * nothing to the first may well add to the second, which is exactly the
     * case for a domicile once a resident has anything to decide.
     *
     * Offered rather than placed, like widgets: there is one settings screen
     * however many capabilities are installed, and only the application can say
     * what order they come in.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function settings(): array;

    /**
     * The mark this capability wears.
     *
     * Its own rather than the application's, because a server can be a domicile
     * and a venue at once and those are two things to be. Somebody at the venue
     * half is somewhere called Tabletop; the same server answering for that
     * person's records is StreetMesh. One mark for the whole application could
     * only ever be right about one of them.
     *
     * Defaulted by the package and overridden by the operator, so nobody has to
     * set this: a capability that is not separately branded answers with the
     * server's own mark, and a server that configures nothing looks exactly as
     * it did before there was a choice to make.
     */
    public function mark(): Mark;
}
