<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use StreetMesh\Server\Venue\Comms;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Visitors;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three documents the comms widget is made of.
 *
 * Each is a whole page served into an iframe of its own, which is the shape
 * rather than an implementation detail. A venue's screens belong to whoever
 * wrote the experience: the badge has to sit above all of them without
 * inheriting a stacking context or a stylesheet it did not ask for, and the
 * stage has to hold live media that a re-render of somebody's game cannot take
 * away. Both of those are what a separate document buys.
 *
 * They are also deliberately dumb about each other. Nothing here knows the
 * badge is next to the stage or that the panel opens above it — the host
 * document arranges them and carries messages between them, because it is the
 * only party that can see all three.
 */
final class CommsController
{
    public function __construct(
        private readonly Parties $parties,
        private readonly Visitors $visitors,
        private readonly Comms $comms,
    ) {}

    /**
     * Asked per request rather than when routes are registered, for the same
     * reason every other switch here is.
     */
    private function refuseUnlessOffered(): void
    {
        if (! $this->comms->offered()) {
            throw new NotFoundHttpException('This venue offers no comms.');
        }
    }

    /**
     * One of the three, and never from a cache.
     *
     * These are iframes, and a browser caches an iframe's document like any
     * other page — but every one of them is written for the person asking and
     * the moment they asked. The stage carries a party's key and the addresses
     * for its tickets and its notes; a stale copy of it belongs to a party
     * somebody has already left.
     *
     * It also hides changes in the least obvious way there is: the page around
     * the frames reloads and they do not, so the two disagree about where
     * things go and the corner comes out crooked for reasons nothing on screen
     * can explain.
     */
    private function surface(string $view, Request $request): Response
    {
        $this->refuseUnlessOffered();

        return response()
            ->view($view, $this->shared($request))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeaders($this->framing());
    }

    /**
     * Saying who may put these in a frame, because something else says nobody.
     *
     * These documents exist to be framed — it is the whole reason they are
     * documents — and locally nothing objects. In front of a deployed venue
     * there is usually a proxy, and a proxy with security headers switched on
     * sends `X-Frame-Options: deny` over everything it serves. That is a
     * sensible default for a site made of pages and fatal to one made of
     * frames: `deny` refuses *same-origin* framing too, so all three surfaces
     * come back 200 and none of them is allowed to appear.
     *
     * Both headers, because they are read by different browsers and one of them
     * we cannot win. `X-Frame-Options` is what an old browser understands, and
     * it is also the one a proxy is likely to overwrite. `frame-ancestors`
     * supersedes it wherever it is understood — a browser seeing both applies
     * this and ignores that — which is what makes this work even when the
     * proxy's `deny` arrives alongside it and we have no way to stop it.
     *
     * `'self'` and not a list. A venue frames its own comms and nobody else's
     * business is served here, so there is nothing to allow that is not already
     * this origin.
     *
     * @return array<string, string>
     */
    private function framing(): array
    {
        return [
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
        ];
    }

    /** The circle in the corner. */
    public function badge(Request $request): Response
    {
        return $this->surface('venue::comms.badge', $request);
    }

    /** What opens when it is pressed. */
    public function panel(Request $request): Response
    {
        return $this->surface('venue::comms.panel', $request);
    }

    /**
     * The row of faces to the left of the badge.
     *
     * Served even when nobody is in a party, because it is where the camera
     * and microphone live and somebody may turn either on at any moment. It
     * draws nothing until they do.
     */
    public function stage(Request $request): Response
    {
        return $this->surface('venue::comms.stage', $request);
    }

    /**
     * What all three need to know.
     *
     * @return array<string, mixed>
     */
    private function shared(Request $request): array
    {
        $visitor = $this->visitors->current($request);
        $party = $this->parties->partyOf($visitor);

        return [
            'here' => $visitor !== null,
            'me' => $visitor?->handle,
            'party' => $party,
            'parties' => $this->parties->enabled(),
            'badge' => (int) config('streetmesh.venue.comms.badge', 60),

            /*
             * The gap between one circle and the next, which is the same
             * padding the badge has around itself — so a face takes exactly as
             * much room as the badge does, and a row of them lines up with it
             * however many there are.
             */
            'pad' => (int) config('streetmesh.venue.comms.pad', 30),

            /*
             * Half of it, which is what separates the badge's circle from the
             * bottom of its own iframe — and therefore how far the faces beside
             * it have to be lifted to sit on the same line.
             */
            'lift' => intdiv((int) config('streetmesh.venue.comms.pad', 30), 2),

            'assets' => (array) config('streetmesh.venue.comms.assets', []),
            'palette' => $this->comms->palette(),
        ];
    }
}
