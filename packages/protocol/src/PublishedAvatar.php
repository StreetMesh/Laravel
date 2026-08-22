<?php

namespace StreetMesh\Protocol;

/**
 * Where a person publishes what they look like.
 *
 * `PublishedMark` one level down, and for the same reason. A venue drawing a
 * party of four needs a picture of four people who live on four servers it has
 * never met, and the alternatives are all worse than a convention: a URL
 * carried in a payload is a field that can point anywhere, and a copy kept by
 * the venue is a claim about somebody rather than evidence about them.
 *
 * So it is served from the address it describes. `collegeman.stme.sh` is the
 * only party that can put a picture at `collegeman.stme.sh/avatar/icon`, and
 * that is the whole of what makes this checkable — a stranger who wants to know
 * whether the collegeman they met is the collegeman they know can go and look.
 *
 * Nothing here is signed, and it does not want to be. An image is not an
 * assertion; holding a signed copy of one does not make it more true, and a
 * signature would only move the question to who signed it.
 *
 * Two paths, and the difference matters. `/avatar` is the model — a body a
 * spatial place puts somebody in. `/avatar/icon` is the picture that stands in
 * for them where there is no space to stand in: a party before anybody turns a
 * camera on, a name in a list, a message. A server that publishes neither is
 * not broken; whoever is asking falls back to a letter, which is what they had.
 */
final class PublishedAvatar
{
    /** The 2D one, for anywhere a person is a name rather than a body. */
    public const ICON = '/avatar/icon';

    /** The 3D one. Reserved, and served by nothing yet. */
    public const MODEL = '/avatar';

    /**
     * Where somebody's icon is, given their handle.
     *
     * HTTPS only, and the host alone — anything else in what was handed in is
     * dropped rather than trusted. This is built from a name that arrived over
     * the wire, and the one thing it must never do is send somebody's browser
     * to an address a stranger chose.
     *
     * A handle is a hostname, which is what makes this possible at all and is
     * worth saying out loud: `collegeman.stme.sh` is both what a person is
     * called and where to ask about them.
     *
     * @return string|null null when there is no handle to ask about
     */
    public static function iconAt(?string $handle): ?string
    {
        $host = self::host($handle);

        return $host === null ? null : 'https://'.$host.self::ICON;
    }

    private static function host(?string $handle): ?string
    {
        $handle = strtolower(trim((string) $handle));

        /*
         * A leading `@`, because that is how people write a handle to each
         * other even where nothing here asks them to.
         */
        $handle = ltrim($handle, '@');

        /*
         * Any scheme already on the front is taken off before another is put
         * there. `https://` prepended to `https://alice.example` parses to a
         * host of `https`, which is a real address that resolves to somebody
         * else's server.
         */
        $handle = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $handle);

        /*
         * Parsed rather than pattern-matched, and a handle has to be *only* a
         * host — no path, no port, no credentials, nothing after it.
         *
         * Stricter than reading the host out and discarding the rest, and the
         * difference is the point. `alice.home.test:8080@evil.example` has a
         * perfectly good host in it: `evil.example`. Keeping just the host
         * would turn a string that names alice into an address that fetches
         * from somebody else, and the picture would be shown under her name.
         * So the presence of anything besides a host is taken as proof this was
         * not a handle, rather than as noise to be trimmed off it.
         */
        $parts = parse_url('https://'.$handle);

        if (! is_array($parts) || array_diff(array_keys($parts), ['scheme', 'host']) !== []) {
            return null;
        }

        $host = $parts['host'] ?? null;

        /*
         * And a handle is a name under somebody's domain, so it has at least
         * one dot in it. Without this, a ticket carrying a bare word would send
         * every browser at the party to a machine on the local network with
         * that name.
         */
        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }
}
