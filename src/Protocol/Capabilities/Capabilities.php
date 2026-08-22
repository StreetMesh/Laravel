<?php

namespace StreetMesh\Server\Protocol\Capabilities;

use RuntimeException;

/**
 * What this server offers, collected from whatever is installed.
 *
 * The arbiter for a problem neither party can settle. A domicile package and a
 * venue package both want the front page and both belong in the navigation, and
 * neither outranks the other — so each registers what it provides here, and the
 * application decides what to do with the collection.
 *
 * It also answers the same question on the wire. A DID document has to say what
 * a server does; asking the same registry means the interface and the protocol
 * cannot drift into disagreeing about it, which they would if each kept its own
 * list.
 */
final class Capabilities
{
    /**
     * The mark a server wears when nothing else has been said.
     *
     * StreetMesh's own, because that is what an unbranded server running this
     * software is. A capability with a name of its own overrides it; one
     * without inherits it, and so does the chrome around both.
     */
    public const OWN_MARK = 'brand/streetmesh-mark';

    /** @var array<string, Capability> */
    private array $registered = [];

    /**
     * @param  array<string, bool|null>  $wanted  what the operator has switched on
     */
    public function __construct(private readonly array $wanted = []) {}

    /**
     * Offered, unless the operator said otherwise.
     *
     * Installing a package is how a capability arrives, and for a server that
     * does one thing that is the whole of the configuration. Two servers built
     * from one codebase need more than that: Domiciles and Tabletop install the
     * same packages and are not the same server.
     *
     * So it is declared rather than inferred. Deducing it from something
     * adjacent — no hub configured, therefore not a venue — would turn a
     * forgotten line into a server that quietly stopped being what its operator
     * thought it was.
     */
    public function register(Capability $capability): void
    {
        if (($this->wanted[$capability->name()] ?? true) === false) {
            return;
        }

        $this->registered[$capability->name()] = $capability;
    }

    public function has(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    /**
     * Whether this server is configured to offer something, whoever has booted.
     *
     * `has()` answers about the registry, so it is only true once that
     * capability's own provider has run — and a package that boots earlier gets
     * "no" for something the server plainly offers. An experience asking
     * whether to register its screens hit exactly that: chess boots before the
     * venue, so on a venue it decided there was no venue.
     *
     * This asks the switch instead, which is settled before anything boots.
     * Absent means offered, the same as everywhere else.
     */
    public function offers(string $name): bool
    {
        return ($this->wanted[$name] ?? true) !== false;
    }

    public function get(string $name): Capability
    {
        return $this->registered[$name]
            ?? throw new RuntimeException("This server does not offer [{$name}].");
    }

    /**
     * The mark a named capability wears, or the server's own.
     *
     * Naming one that is not installed is not an error here, unlike `get`.
     * Chrome is shared, and a layout asking for the venue's mark on a server
     * with no venue is asking a reasonable question with an obvious answer — a
     * screen must not fail to render because a package was removed.
     */
    public function mark(?string $name = null, ?string $preferred = null): Mark
    {
        /*
         * Naming nobody means the chrome, which belongs to no capability in
         * particular — a sidebar is drawn around whatever screen you are on.
         * The one that greets people answers for it, because that is the thing
         * this server is to somebody looking at it: a venue-only server is
         * Tabletop everywhere, and on a blended server the operator has already
         * had to say which one greets strangers, so there is no second question
         * to ask them.
         */
        $capability = $name === null
            ? $this->greeter($preferred)
            : ($this->registered[$name] ?? null);

        return $capability?->mark() ?? new Mark(self::OWN_MARK);
    }

    /**
     * Whichever capability greets people.
     *
     * The operator's preference where there is one, and otherwise the first
     * that offers a front page — so a server with a single capability needs no
     * configuration at all, which is most of them.
     *
     * Extracted because three things now ask this same question and the answer
     * has to be the same for all of them. A front page welcoming visitors above
     * a button signing residents in, under a mark belonging to neither, would
     * be three servers talking at once.
     */
    private function greeter(?string $preferred = null): ?Capability
    {
        if ($preferred !== null && $this->has($preferred)) {
            return $this->get($preferred);
        }

        foreach ($this->registered as $capability) {
            if ($capability->frontPage() !== null) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return array<string, Capability>
     */
    public function all(): array
    {
        return $this->registered;
    }

    /**
     * The names, as a discovery document publishes them.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->registered);
    }

    /**
     * What a stranger sees at the root.
     *
     * Configured rather than inferred, because a server has one root and
     * guessing would mean the answer changed when a package was installed.
     * Falls back to whatever offers one, so a server with a single capability
     * needs no configuration at all.
     */
    public function frontPage(?string $preferred = null): ?string
    {
        return $this->greeter($preferred)?->frontPage();
    }

    /**
     * The way in for whichever capability greets people.
     *
     * Asked with the same preference as `frontPage`, so the page and the button
     * under it always come from the same capability. A front page that welcomes
     * visitors above a button that signs residents in would be two servers
     * talking at once.
     *
     * @return null|array{label: string, route: string}
     */
    public function frontAction(?string $preferred = null): ?array
    {
        return $this->greeter($preferred)?->frontAction();
    }

    /**
     * Whoever is here, from whichever capability can see them.
     *
     * The first answer wins, and on a server that is both a domicile and a
     * venue there can genuinely be two: somebody signed in as a resident who
     * has also arrived as a visitor. Registration order decides, which is the
     * same rule the front page uses, and either answer is true.
     *
     * @return null|array{name: string, leave: array{label: string, route: string}}
     */
    public function whoever(): ?array
    {
        foreach ($this->registered as $capability) {
            $whoever = $capability->whoever();

            if ($whoever !== null) {
                return $whoever;
            }
        }

        return null;
    }

    /**
     * Every panel on offer, optionally narrowed and ordered by the operator.
     *
     * An arrangement naming a widget nothing provides is skipped rather than
     * fatal: capabilities are installed and removed, and a server should not
     * fail to render a page because a package it no longer has is still listed
     * in a configuration file.
     *
     * @param  array<int, string>|null  $arrangement  widget names, in order
     * @return array<int, Widget>
     */
    public function widgets(?array $arrangement = null): array
    {
        $offered = [];

        foreach ($this->registered as $capability) {
            foreach ($capability->widgets() as $widget) {
                $offered[$widget->name()] = $widget;
            }
        }

        if ($arrangement === null) {
            return array_values($offered);
        }

        return array_values(array_filter(array_map(
            fn (string $name): ?Widget => $offered[$name] ?? null,
            $arrangement,
        )));
    }

    /**
     * Everything the installed capabilities want in the navigation, in the
     * order they were registered.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function navigation(): array
    {
        return array_merge(...array_map(
            fn (Capability $capability): array => $capability->navigation(),
            array_values($this->registered),
        ) ?: [[]]);
    }

    /**
     * And everything they want on the settings screen, likewise.
     *
     * @return array<int, array{label: string, route: string, icon?: string}>
     */
    public function settings(): array
    {
        return array_merge(...array_map(
            fn (Capability $capability): array => $capability->settings(),
            array_values($this->registered),
        ) ?: [[]]);
    }
}
