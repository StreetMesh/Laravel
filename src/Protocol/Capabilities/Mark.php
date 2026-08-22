<?php

namespace StreetMesh\Server\Protocol\Capabilities;

/**
 * A capability's own mark, in the two grounds it needs.
 *
 * Named rather than listed. A mark that carries its own ground needs a second
 * drawing for a dark surface — the ground goes transparent so the page shows
 * through — and every pack built for this server is built the same way: one
 * base name, `-small` and `-dark-small` beside it. So an operator names the
 * pair and the convention supplies the rest, instead of writing two paths that
 * can disagree with each other.
 *
 * The small variants, because every caller here draws this between 32 and 48
 * pixels. Below 32 the packs ship a micro variant, and nothing in this
 * application is small enough to want one yet.
 *
 * Why a capability has one at all: a server can be a domicile and a venue at
 * once, and those are two things to be rather than one thing wearing two
 * hats. Somebody arriving at the venue half of this server is at Tabletop; the
 * same server answering for a resident's records is StreetMesh. One mark for
 * the application could only ever be right about one of them.
 */
final class Mark
{
    /**
     * @param  string  $base  a public path with no variant or extension on it,
     *                        such as `brand/tabletop-mark`
     */
    public function __construct(private readonly string $base) {}

    /**
     * Public paths rather than URLs.
     *
     * Turning one into an address is the view's job and needs a booted
     * application to do it. This is a pair of filenames and a naming rule, and
     * it stays testable by being nothing more than that.
     */
    public function light(): string
    {
        return $this->base.'-small.svg';
    }

    public function dark(): string
    {
        return $this->base.'-dark-small.svg';
    }

    /**
     * Whether this is the same mark as another.
     *
     * Asked on a blended server, where drawing both halves' marks side by side
     * says "two parties" — and says it wrongly when an operator has configured
     * neither and both are the server's own.
     */
    public function is(self $other): bool
    {
        return $this->base === $other->base;
    }
}
