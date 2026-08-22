<?php

namespace StreetMesh\Server\Venue\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use StreetMesh\Server\Venue\Gatherings\Gathering;

/**
 * Somebody arrived at a gathering, or left one.
 *
 * Fired whenever the hub says the room changed. Anything showing a count can
 * listen instead of asking — which is the point: a lobby polling every half
 * minute is a lobby that is wrong for up to half a minute and busy the rest of
 * the time.
 *
 * Broadcast on a channel per experience rather than per gathering, because the
 * screen that cares is a list of tables rather than one of them. A board is
 * already in its room and knows this sooner than any of this could tell it.
 *
 * Public, and it carries a count rather than names. How many people are at a
 * table is what the venue is willing to say to anybody looking at the menu; who
 * they are is the room's business and belongs to the people in it.
 *
 * Sent immediately rather than queued. Queued, this is a message about who is
 * in a room *now* that waits its turn behind everything else — and on a server
 * with no worker running it is not late, it is never. That is not hypothetical:
 * these sat unsent in a jobs table while the socket they were meant for stayed
 * silent.
 *
 * It is a few bytes to a local socket, which is not work worth deferring. The
 * cost of it failing is a count that is briefly wrong, and the venue asks the
 * hub again within the minute anyway.
 */
final class Occupied implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Gathering $gathering,
        public readonly int $occupants,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('streetmesh.experience.'.$this->gathering->experience)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'gathering' => $this->gathering->key,
            'occupants' => $this->occupants,
        ];
    }
}
