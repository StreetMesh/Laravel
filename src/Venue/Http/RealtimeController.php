<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Gatherings\Settling;
use StreetMesh\Server\Venue\Realtime\Occupancy;
use StreetMesh\Server\Venue\Realtime\Occupied;
use StreetMesh\Server\Venue\Realtime\Secrets;

/**
 * What the hub tells this venue.
 *
 * The only direction between the two that is not one-way. A ticket is signed
 * here and merely verified there; a result can be asked for. This is the hub
 * speaking unprompted, and it has to, because the two things worth knowing
 * happen when nobody is looking: the last person leaving a table, and a game
 * ending after both players have closed their tabs.
 *
 * A hub holds no key of its own, so it is recognised by a shared secret. That
 * is the whole of the trust here, and it is worth being plain about what it
 * buys: this venue believes the room state and the result of a gathering it
 * opened. It does not believe anything about who somebody is — that came from
 * a ticket this venue signed, and it does not come back through here.
 */
final class RealtimeController
{
    public function __invoke(
        Request $request,
        Secrets $secrets,
        Occupancy $occupancy,
    ): JsonResponse {
        if (! $secrets->accepts($request->bearerToken() ?? $request->header('X-StreetMesh-Secret'))) {
            return response()->json(['heard' => false], 401);
        }

        $gathering = Gathering::query()->keyed($this->keyIn((string) $request->input('room')))->first();

        if ($gathering === null) {
            // A room this venue did not open. Not an error — a hub may serve
            // more than one venue, and a venue may have forgotten a gathering
            // the hub is still holding.
            return response()->json(['heard' => false, 'because' => 'no such gathering'], 404);
        }

        /** @var array<int, array{name: string, seat: string}> $occupants */
        $occupants = (array) $request->input('occupants', []);

        $occupancy->remember($gathering->room(), $occupants);

        Occupied::dispatch($gathering, count($occupants));

        $this->settle($gathering, (array) $request->input('result'));

        return response()->json(['heard' => true]);
    }

    /**
     * A finished gathering, handed on to be written down.
     *
     * Queued rather than done here. Settling means calling each participant's
     * own server, and the hub is waiting on this answer — a domicile having a
     * slow afternoon would become a hub blocked on a room that has already
     * ended.
     *
     * @param  array<string, mixed>  $result
     */
    private function settle(Gathering $gathering, array $result): void
    {
        if ($result === [] || ! $gathering->isOpen()) {
            return;
        }

        Settling::dispatch($gathering, $result);
    }

    /**
     * The gathering's own name, out of the name the hub knows a room by.
     *
     * A room is the experience and the gathering joined by a slash. The hub
     * repeats what it was given; this reads back the half that identifies the
     * gathering here.
     */
    private function keyIn(string $room): string
    {
        $parts = explode('/', $room);

        return end($parts) ?: '';
    }
}
