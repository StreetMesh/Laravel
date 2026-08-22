<?php

namespace StreetMesh\Protocol;

/**
 * Where a server publishes what it looks like.
 *
 * A convention rather than a negotiation. Two servers that have never met need
 * a picture of each other for one screen — the moment somebody is asked whether
 * to let one talk to the other — and everything else here that crosses the
 * network is fetched, checked and signed. This is the one thing that cannot
 * usefully be any of those: an image is not an assertion, and nothing about
 * holding a signed copy of one makes it more true.
 *
 * So it is served from the origin it describes, at a path fixed here, and read
 * straight from there. That is what makes it evidence rather than a claim.
 * `tabletop.streetmesh.com` is the only party that can put a picture at
 * `tabletop.streetmesh.com`, and a domicile repeating the picture on its behalf
 * would be vouching for something it has no way to check.
 *
 * Not carried in client metadata, which was the other option. A `logo_uri`
 * there can point anywhere, so a document would be asserting where its own face
 * lives — one more field to validate, and a way for a venue to have somebody
 * else's mark shown beside its name.
 *
 * Two paths because a mark that carries its own ground needs a second drawing
 * for a dark surface. A server that serves neither is not broken; whoever is
 * asking falls back to a glyph, which is what they had before.
 */
final class PublishedMark
{
    public const LIGHT = '/mark.svg';

    public const DARK = '/mark-dark.svg';

    /**
     * The pair of addresses a host publishes its mark at.
     *
     * HTTPS only, and the host alone — anything else in what was handed in is
     * dropped rather than trusted. This is built from a name that arrived over
     * the wire, and the one thing it must never do is send somebody's browser
     * to an address a stranger chose.
     *
     * @return array{light: string, dark: string}|null  null when there is no host to ask
     */
    public static function at(?string $host): ?array
    {
        $host = strtolower(trim((string) $host));

        /*
         * Any scheme already on the front is taken off before another is put
         * there. `https://` prepended to `https://games.example` parses to a
         * host of `https`, which is a real address that resolves to somebody
         * else's server — and `https://` on its own became `https://https/`.
         */
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);

        /*
         * Parsed rather than pattern-matched, and only a bare host survives.
         * `evil.example/../` and `host:8080@elsewhere` are both things a string
         * can contain, and neither is a host.
         */
        $host = parse_url('https://'.$host, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return [
            'light' => 'https://'.$host.self::LIGHT,
            'dark' => 'https://'.$host.self::DARK,
        ];
    }
}
