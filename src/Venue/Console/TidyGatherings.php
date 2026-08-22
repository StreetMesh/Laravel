<?php

namespace StreetMesh\Server\Venue\Console;

use Illuminate\Console\Command;
use StreetMesh\Server\Venue\Gatherings\Gatherings;
use StreetMesh\Server\Venue\Gatherings\Results;

/**
 * Clear away tables nobody came to.
 *
 * Somebody opens a game, waits, and closes the tab. What is left is an
 * invitation nobody can accept — the person who sent it has gone, and anybody
 * arriving at it is joining a game with one player who is not there. Left
 * alone they accumulate, and a lobby of them makes a venue look busy and
 * abandoned at the same time.
 *
 * Only ever a table with one seat. Two people meeting is a game, whether or not
 * a move was played, and a game is somebody's to conclude rather than this
 * command's to delete.
 */
class TidyGatherings extends Command
{
    protected $signature = 'gatherings:tidy
        {--minutes=10 : How long a table may wait for somebody}
        {--pretend : Say what would go, and take nothing}';

    protected $description = 'Clear away tables nobody came to';

    public function handle(Gatherings $gatherings, Results $results): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $waiting = $gatherings->waiting($minutes);

        if ($waiting->isEmpty()) {
            return self::SUCCESS;
        }

        /*
         * The hub is asked first, and its silence is not taken for agreement.
         * Whether anybody is at a table is something only the hub knows, and a
         * hub that cannot be reached answers "nobody is anywhere" — which,
         * acted on, would clear every table on the worst possible minute.
         */
        if (! $results->reachable()) {
            $this->components->warn('The hub is not answering, so nothing was cleared.');

            return self::SUCCESS;
        }

        $present = $results->at($waiting);
        $cleared = 0;

        foreach ($waiting as $gathering) {
            // Somebody is sitting there. Waiting is not abandoning.
            if (($present[$gathering->room()] ?? []) !== []) {
                continue;
            }

            $this->line("  <fg=gray>clearing</> {$gathering->key}");

            if (! $this->option('pretend')) {
                $gathering->delete();
            }

            $cleared++;
        }

        $this->components->info(
            $this->option('pretend')
                ? "{$cleared} would go, of {$waiting->count()} waiting."
                : "Cleared {$cleared}, of {$waiting->count()} waiting."
        );

        return self::SUCCESS;
    }
}
