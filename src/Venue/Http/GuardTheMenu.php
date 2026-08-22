<?php

namespace StreetMesh\Server\Venue\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * Whether what is on is anybody's business.
 *
 * A venue decides. A chess club posts its programme on the door; a private
 * members' club does not, and neither is more correct than the other.
 *
 * Asked per request rather than when routes are registered, and that is not a
 * detail: a setting consulted at boot gets baked into a cached route table, so
 * changing it would appear to do nothing until somebody remembered to clear the
 * cache. A setting that silently fails to take effect is worse than one that
 * does not exist.
 *
 * Either way the experiences themselves stay behind the door. Seeing that chess
 * is on offer is not the same as sitting down at a table.
 */
final class GuardTheMenu
{
    public function __construct(private readonly RequireVisitor $door) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return config('streetmesh.venue.gallery') === 'visitors'
            ? $this->door->handle($request, $next)
            : $next($request);
    }
}
