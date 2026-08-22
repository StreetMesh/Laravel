<?php

namespace StreetMesh\Server\Venue\Http;

use Closure;
use Illuminate\Http\Request;
use StreetMesh\Server\Venue\Visitors;

/**
 * This part of the venue is for people who have arrived.
 *
 * Not authentication in the usual sense — there is nothing here to be
 * authenticated against. It asks only whether this browser is acting under a
 * permission somebody's own server gave us, and sends them to the door if not.
 *
 * Where they were heading is remembered, because being asked to identify
 * yourself and then dumped at the entrance is the small rudeness that makes a
 * federated sign-in feel worse than a local one.
 */
final class RequireVisitor
{
    public function __construct(private readonly Visitors $visitors) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->visitors->current($request) !== null) {
            return $next($request);
        }

        if ($this->worthComingBackTo($request)) {
            $request->session()->put(Visitors::INTENDED_KEY, $request->fullUrl());
        }

        return redirect()->route('venue.connect');
    }

    /**
     * Is this a place a person was going, or a request a script was making?
     *
     * The distinction was missing and the failure was quiet and confusing.
     * Every guarded request wrote down where it was going, and the panel polls
     * one of them once a second for as long as it is open — so revoking a
     * permission left the last poll as "where you were heading", and signing
     * back in delivered somebody to a JSON endpoint belonging to the party
     * system. Nothing looked broken until the very end.
     *
     * The rule is that only a document is somewhere to be returned to. A poll,
     * a beacon, a frame and a form post are all things that happen *at* a
     * place rather than being one.
     */
    private function worthComingBackTo(Request $request): bool
    {
        /*
         * A redirect can only replay a GET. Sending somebody back to a POST
         * they were part-way through would repeat it as a page load, which is
         * either nothing or something nobody asked for twice.
         */
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->ajax() || $request->expectsJson()) {
            return false;
        }

        /*
         * And the browser's own word for what it was fetching, which is the
         * only thing that can tell a top-level navigation from an iframe or a
         * `fetch` that happens to want HTML. `document` is a person going
         * somewhere; `empty`, `iframe`, `image` and the rest are not.
         *
         * Absent is treated as a document, because a browser too old to send it
         * is also too old to be second-guessed — and the two checks above have
         * already answered for everything this application actually asks for.
         */
        $destination = $request->headers->get('Sec-Fetch-Dest');

        return $destination === null || $destination === 'document';
    }
}
