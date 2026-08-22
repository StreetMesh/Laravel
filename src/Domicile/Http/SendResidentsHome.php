<?php

namespace StreetMesh\Server\Domicile\Http;

use Closure;
use Illuminate\Http\Request;
use StreetMesh\Server\Protocol\Identity\Identities;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person who typed a resident's address into a browser.
 *
 * `collegeman.stme.sh` is a hostname, and a hostname exists so that a machine
 * resolving a handle can find `/.well-known/atproto-did`. It was never meant to
 * be browsed. Somebody who types it in is not resolving anything — they are
 * asking about a person — so they are sent to that person's page.
 *
 * Only on browser routes. Handle resolution and everything else a stranger's
 * server asks for is registered outside the `web` group, so none of it passes
 * through here: a redirect on `.well-known/atproto-did` would break the one
 * thing the subdomain exists for.
 */
final readonly class SendResidentsHome
{
    public function __construct(private Identities $identities) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $server = $this->identities->forServer()->handle;

        // This server's own name, which is where everything is served from.
        if ($server === null || $host === $server) {
            return $next($request);
        }

        $resident = $this->identities->byHandle($host);

        /*
         * A host that is not a resident is left alone. It may be an alias, a
         * preview URL, or a name pointed here by somebody else — none of which
         * is this middleware's business to redirect.
         */
        if ($resident === null || $resident->is_server) {
            return $next($request);
        }

        /*
         * Built against the server's own name rather than this one.
         *
         * `route()` uses the host of the request it is answering, which here is
         * the resident's — so the obvious version sends somebody from
         * `alice.example/` to `alice.example/profile/alice.example`, a redirect
         * to the same place they already were.
         *
         * Permanent, because it always will be: a person's page lives on the
         * server, and a resident's hostname is never going to serve one.
         */
        return redirect()->away(
            $request->getScheme().'://'.$server.route('domicile.profile', $resident->handle, absolute: false),
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }
}
