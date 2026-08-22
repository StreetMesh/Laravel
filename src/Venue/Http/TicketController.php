<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Gatherings\Gatherings;
use StreetMesh\Server\Venue\Visitors;
use Throwable;

/**
 * A way in, for a browser about to open a websocket.
 *
 * Everything the hub will check has already been decided by the time this
 * answers: who this visitor is, whether they belong at this gathering, and in
 * which seat. None of that is taken from the request — the seat comes from the
 * one this venue put them in, so asking for a different one gets the one they
 * actually have.
 *
 * The hub's address comes back with it, because a browser has no other way to
 * learn it and hard-coding it into a page would mean an operator could not move
 * their realtime half without editing an experience's templates.
 */
final class TicketController
{
    public function __construct(
        private readonly Gatherings $gatherings,
        private readonly Visitors $visitors,
    ) {}

    public function __invoke(Request $request, string $key): JsonResponse
    {
        /*
         * Null is an ordinary answer here, not a refusal.
         *
         * Somebody may be nobody in particular: a passer-by who followed a link
         * to a game and has never been to this venue. Whether that is somebody
         * to let in is the experience's decision about its own gathering, so it
         * is made there rather than turned away at this door.
         */
        $visitor = $this->visitors->current($request);

        $gathering = Gathering::query()->keyed($key)->first();

        if ($gathering === null) {
            return response()->json(['error' => 'There is nothing here by that name.'], 404);
        }

        try {
            $ticket = $this->gatherings->admit($gathering, $visitor);
        } catch (Throwable $refused) {
            return response()->json(['error' => $refused->getMessage()], 403);
        }

        return response()->json([
            'ticket' => $ticket,

            /*
             * The name the hub knows this room by, sent rather than assembled
             * in the browser. It has to match what the ticket says exactly, and
             * two places building the same string is two places to get it
             * wrong.
             */
            'room' => $gathering->room(),
            'experience' => $gathering->experience,

            'hub' => config('streetmesh.venue.hub'),
        ]);
    }
}
