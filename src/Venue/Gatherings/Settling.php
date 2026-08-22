<?php

namespace StreetMesh\Server\Venue\Gatherings;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Experiences\Settles;

/**
 * Writing down a finished gathering, out of the way.
 *
 * Settling means calling somebody else's server — one per participant, each of
 * them a domicile this venue does not run and cannot depend on. Done inline it
 * held up whoever happened to bring the news: a browser waiting on a redirect,
 * or the hub waiting to be told its announcement was heard.
 *
 * Queued, a slow domicile is slow rather than fatal, and a domicile that is
 * down for a minute is a record that arrives a minute later instead of one that
 * never arrives at all. That is the whole reason: this is the one thing here
 * that has to reach somebody else's machine, and it is the one thing worth
 * retrying.
 */
final class Settling implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * A domicile that is down comes back. Five attempts over a few minutes
     * covers a restart or a deploy without hammering somebody who is having a
     * bad afternoon.
     */
    public int $tries = 5;

    /**
     * Longer each time, because the second failure means something different
     * from the first.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 120, 300];

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public readonly Gathering $gathering,
        public readonly array $result,
    ) {}

    /**
     * One at a time per gathering.
     *
     * Two messengers carry this news — a browser noticing the board has stopped
     * and the hub announcing the room has ended — and either may arrive first,
     * or both at once. `settle` refuses a gathering that is already concluded,
     * but two jobs running together could both read it as open and both write.
     */
    public function uniqueId(): string
    {
        return $this->gathering->key;
    }

    public function handle(Experiences $experiences): void
    {
        // Somebody else got here first. Not a failure: it is the ordinary
        // outcome of two messengers with the same news.
        if (! $this->gathering->fresh()?->isOpen()) {
            return;
        }

        $experience = $experiences->get($this->gathering->experience);

        // A venue holds no opinion about what a result is. It knows which
        // experience this belongs to and whether that experience cares.
        if ($experience instanceof Settles) {
            $experience->settle($this->gathering, $this->result);
        }
    }
}
