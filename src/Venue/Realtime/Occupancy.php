<?php

namespace StreetMesh\Server\Venue\Realtime;

use Illuminate\Support\Facades\Cache;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Gatherings\Results;

/**
 * How many people are at each table, without asking every time.
 *
 * Two ways in. The hub announces every arrival and departure, which keeps this
 * current at the moment it changes; and anything not already known is fetched,
 * which covers a venue that has only just started or a hub that has only just
 * restarted.
 *
 * The lifetime is a backstop rather than the mechanism. If announcements are
 * arriving, nothing here is ever stale enough to matter and the expiry is never
 * reached. What it covers is an announcement that never came — a dropped
 * request, a hub restarted while a table sat empty — and it decides how long a
 * wrong number can survive, not how fresh a right one is.
 */
final readonly class Occupancy
{
    /**
     * Long enough that announcements do the work, short enough that a missed
     * one heals within a minute.
     */
    private const SECONDS = 60;

    public function __construct(private Results $results) {}

    /**
     * Who is at each of these, from what is known and what can be found out.
     *
     * @param  iterable<Gathering>  $gatherings
     * @return array<string, array<int, array{name: string, seat: string}>>
     */
    public function at(iterable $gatherings): array
    {
        $known = [];
        $unknown = [];

        foreach ($gatherings as $gathering) {
            $room = $gathering->room();
            $held = Cache::get($this->key($room));

            if (is_array($held)) {
                $known[$room] = $held;

                continue;
            }

            $unknown[] = $gathering;
        }

        if ($unknown === []) {
            return $known;
        }

        /*
         * One question for everything not already known, rather than one each.
         * A lobby listing a dozen tables on a cold cache is a dozen rooms and
         * one request.
         */
        foreach ($this->results->at($unknown) as $room => $occupants) {
            $this->remember($room, $occupants);
            $known[$room] = $occupants;
        }

        return $known;
    }

    /**
     * What the hub says is true now.
     *
     * @param  array<int, array{name: string, seat: string}>  $occupants
     */
    public function remember(string $room, array $occupants): void
    {
        Cache::put($this->key($room), $occupants, self::SECONDS);
    }

    /**
     * Forget a room, so the next question is asked rather than answered.
     *
     * For anything that makes what is held here doubtful rather than wrong: a
     * hub announcing it has restarted, having lost every room it was holding.
     */
    public function forget(string $room): void
    {
        Cache::forget($this->key($room));
    }

    private function key(string $room): string
    {
        // Hashed because a room name is an NSID and a ULID joined by a slash,
        // and cache drivers differ about what they will take as a key.
        return 'streetmesh:occupancy:'.sha1($room);
    }
}
