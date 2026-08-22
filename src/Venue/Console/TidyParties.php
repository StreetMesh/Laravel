<?php

namespace StreetMesh\Server\Venue\Console;

use Illuminate\Console\Command;
use StreetMesh\Server\Venue\Parties\Parties;

/**
 * Break up parties nobody is in.
 *
 * A party disbands itself when the last person walks out of it, and that covers
 * everybody who leaves by pressing something. It does not cover the ordinary
 * way a visit actually ends: somebody gives their permission back, or it
 * expires, and the delegation their membership was held against goes — taking
 * the membership with it and leaving the party standing with nobody in it.
 *
 * Nothing notices at the time, because nothing was watching. What is left is a
 * name an invitation could still be accepted against, which would put somebody
 * alone in a room they were asked into by people who are no longer here.
 *
 * The hub is not consulted, and does not need to be. Whether anybody is
 * *connected* is its business; whether anybody is still a member is this
 * server's own record, and that is the question being asked.
 */
class TidyParties extends Command
{
    protected $signature = 'parties:tidy
        {--pretend : Say what would go, and take nothing}';

    protected $description = 'Break up parties nobody is in';

    public function handle(Parties $parties): int
    {
        if (! $parties->enabled()) {
            return self::SUCCESS;
        }

        $deserted = $parties->deserted();

        if ($deserted->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($deserted as $party) {
            $this->line("  <fg=gray>breaking up</> {$party->key}");

            if (! $this->option('pretend')) {
                $parties->disband($party);
            }
        }

        $this->components->info(
            $this->option('pretend')
                ? "{$deserted->count()} would break up."
                : "Broke up {$deserted->count()}."
        );

        return self::SUCCESS;
    }
}
