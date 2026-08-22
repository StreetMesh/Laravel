<?php

namespace StreetMesh\Server\Domicile\Avatars;

/**
 * What somebody looks like before they have said.
 *
 * A domicile always answers for its own residents. Somebody who has published
 * nothing still has a name, and their first letter on a ground of their own is
 * more than nothing — so this is what `/avatar/icon` serves until there is a
 * picture, rather than a refusal that every caller has to know how to handle.
 *
 * Deliberately a letter and deliberately not a silhouette. A generic figure
 * says only "a person", which the caller already knew; a letter says which
 * person, and everywhere that stands in for a face today is already drawing
 * exactly that. Answering with less than the thing being replaced would make
 * every caller worse off for having asked.
 *
 * The colour is derived from the whole handle rather than chosen, so it is
 * stable for as long as the name is and two residents of one server are
 * unlikely to share it. That makes a letter a weak kind of recognition —
 * enough to tell four people apart in a row of circles, and nothing anybody
 * should be asked to authenticate on.
 *
 * SVG because it is a few hundred bytes and scales to any circle. That it is
 * also a document format is the reason for the headers on the response: these
 * bytes come back from the origin that answers for somebody's identity.
 */
final class Letter
{
    /**
     * Held so the drawing can change without a stale copy surviving in a cache.
     *
     * The entity tag for a letter is built from this and the handle, where the
     * tag for a real picture is the picture's own name. Bump it and every
     * letter is refetched; leave it and none are.
     */
    private const DRAWING = 1;

    private function __construct(
        public readonly string $bytes,
        public readonly string $etag,
    ) {}

    public static function for(string $handle): self
    {
        $handle = strtolower(trim($handle));
        $initial = self::initial($handle);

        /*
         * Hue from the name, saturation and lightness fixed. Letting all three
         * vary produces the occasional unreadable pairing — pale yellow under
         * white — and a fixed pair that works everywhere is worth more than the
         * extra variety.
         */
        $hue = hexdec(substr(sha1($handle), 0, 4)) % 360;

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-label="{$initial}">
            <rect width="256" height="256" fill="hsl({$hue}, 38%, 42%)"/>
            <text x="128" y="128" dy=".35em" fill="#ffffff" text-anchor="middle" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif" font-size="112" font-weight="600">{$initial}</text>
            </svg>
            SVG;

        return new self(
            $svg,
            sprintf('letter-%d-%s', self::DRAWING, substr(sha1($handle), 0, 16)),
        );
    }

    /**
     * The first character of the label, which is the part that is theirs.
     *
     * `aaron.server.test` is an A. The rest of a handle is the server, and
     * every resident of one shares it — so a letter taken from the whole thing
     * would be the same letter for everybody who lives here.
     *
     * Escaped on the way out even though a handle cannot contain any of what is
     * being escaped. This lands inside a document served from the origin that
     * answers for somebody's identity, and "the input cannot be dangerous" is
     * the sentence that precedes finding out it can.
     */
    private static function initial(string $handle): string
    {
        $label = strtok($handle, '.') ?: $handle;
        $first = mb_substr($label, 0, 1);

        return htmlspecialchars(
            mb_strtoupper($first === '' ? '?' : $first),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1,
            'UTF-8',
        );
    }
}
