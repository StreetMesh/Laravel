<?php

namespace StreetMesh\Server\Venue\Http;

use Closure;
use Illuminate\Http\Request;
use StreetMesh\Server\Venue\Parties\Parties;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A venue that does not do parties has nowhere to do them.
 *
 * Switched off means gone rather than hidden, which is the rule the venue
 * capability itself follows — a server that is not a venue has no door to one,
 * and a venue with parties off has no party to knock at.
 *
 * Asked per request rather than when routes are registered. A setting consulted
 * at boot gets baked into a cached route table, and the symptom is an operator
 * turning parties on and nothing whatsoever happening until somebody thinks to
 * clear a cache.
 */
final class RequireParties
{
    public function __construct(private readonly Parties $parties) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->parties->enabled()) {
            throw new NotFoundHttpException('This venue does not do parties.');
        }

        return $next($request);
    }
}
