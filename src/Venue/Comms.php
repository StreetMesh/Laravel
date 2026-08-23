<?php

namespace StreetMesh\Server\Venue;

use Illuminate\Http\Request;
use StreetMesh\Protocol\PublishedAvatar;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Venue\Parties\Parties;

/**
 * What the comms widget needs to know, in one place.
 *
 * Two surfaces ask for it — the snippet the host page includes, and the
 * documents served into the frames — and they have to agree. They did not for a
 * while: the frames knew which party somebody was in and the page around them
 * did not, which was survivable only while the media lived in a frame.
 *
 * It does not any more. A navigation replaces the page's body and reloads every
 * iframe in it, so anything holding a camera has to live in the one place that
 * survives: the host document. The frames render; this is what the host is told
 * so it can do the rest.
 */
final class Comms
{
    public function __construct(
        private readonly Parties $parties,
        private readonly Visitors $visitors,
    ) {}

    /**
     * Whether this server offers comms at all.
     *
     * The capability as well as the setting, and the capability is the half
     * that was missing. Comms is included by the *application's* chrome, which
     * a domicile draws too — but the routes behind it belong to the venue
     * package's own group and are never registered on a server that is not a
     * venue. So a domicile emitted three frames pointing at three addresses it
     * does not have, and answered 404 into each of them on every page.
     */
    public function offered(): bool
    {
        return (bool) config('streetmesh.venue.comms.enabled', true)
            && app(Capabilities::class)->offers('venue');
    }

    /**
     * The badge is a circle of this many pixels, in a frame this much larger.
     *
     * @return array{badge: int, pad: int, lift: int, margin: int, margin_narrow: int}
     */
    public function shape(): array
    {
        $pad = (int) config('streetmesh.venue.comms.pad', 30);

        return [
            'badge' => (int) config('streetmesh.venue.comms.badge', 60),
            'pad' => $pad,

            /*
             * Half the padding, which is what separates the badge's circle from
             * the bottom of its own frame — and therefore how far the faces
             * beside it are lifted to sit on the same line.
             */
            'lift' => intdiv($pad, 2),

            'margin' => (int) config('streetmesh.venue.comms.margin', 40),
            'margin_narrow' => (int) config('streetmesh.venue.comms.margin_narrow', 20),
        ];
    }

    /**
     * The few colours the widget draws itself with.
     *
     * Read from one place because two of its documents load no stylesheet and
     * cannot reach a token — see the note in the config.
     *
     * @return array{ink: string, paper: string, accent: string}
     */
    public function palette(): array
    {
        return [
            'ink' => (string) config('streetmesh.venue.comms.palette.ink', '#14181A'),
            'paper' => (string) config('streetmesh.venue.comms.palette.paper', '#fafafa'),
            'accent' => (string) config('streetmesh.venue.comms.palette.accent', '#00FF99'),
        ];
    }

    /**
     * Everything the host document needs to hold the media itself.
     *
     * The party is named here rather than discovered by the frames, because the
     * thing that connects to it now lives in the page. Null for somebody in no
     * party, which is most people most of the time.
     *
     * @return array<string, mixed>
     */
    public function forHost(Request $request): array
    {
        $visitor = $this->visitors->current($request);
        $party = $this->parties->partyOf($visitor);

        return [
            'here' => $visitor !== null,
            'me' => $visitor?->handle,

            /*
             * Your own face, at your own address — fetched from your domicile
             * exactly as everybody else's is, rather than from anything this
             * venue happens to be holding. So a picture that is broken for the
             * party is broken here too, which is the only way anybody would
             * find out.
             */
            'myIcon' => PublishedAvatar::iconAt($visitor?->handle),

            'party' => $party === null ? null : [
                'key' => $party->key,
                'ticketUrl' => route('venue.parties.ticket', $party->key),
                'signalsUrl' => route('venue.parties.signals', $party->key),
            ],
        ];
    }

    /**
     * The entry that arranges this widget, named the way the manifest names it.
     *
     * Vite keys an entry by where the file sits relative to the application
     * root, and this package does not sit anywhere in particular:
     * `vendor/streetmesh/laravel` once installed, `packages/laravel` in a path
     * repository, a sibling directory while it is being worked on. Writing one
     * of those into the view is exactly how this broke — the path was set down
     * while the venue was its own package and went on pointing there after it
     * stopped being one, so the widget rendered and the script that drives it
     * never loaded.
     *
     * Symlinks are resolved on the directory and not the file, because
     * `vite/streetmesh.js` resolves them the same way. A key that disagrees with
     * the manifest is a page with no script and no error to say so.
     */
    public function hostEntry(): string
    {
        $package = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);

        return $this->relativeToBase($package.'/resources/js/comms/host.js');
    }

    /**
     * A path as the application root would write it, walking up where it has to.
     */
    private function relativeToBase(string $path): string
    {
        $root = explode('/', trim(realpath(base_path()) ?: base_path(), '/'));
        $file = explode('/', trim($path, '/'));

        while ($root !== [] && $file !== [] && $root[0] === $file[0]) {
            array_shift($root);
            array_shift($file);
        }

        return implode('/', array_merge(array_fill(0, count($root), '..'), $file));
    }
}
