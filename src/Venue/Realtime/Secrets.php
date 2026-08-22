<?php

namespace StreetMesh\Server\Venue\Realtime;

use RuntimeException;

/**
 * The secret a hub speaks to this venue with.
 *
 * Everything else between these two is one-way and needs no secret: a ticket is
 * signed by the venue and merely verified by the hub, and a result is asked for
 * rather than announced. This is the one direction that cannot work that way —
 * a hub telling a venue something has to be a hub the venue can recognise, and
 * a hub holds no key of its own.
 *
 * So: a shared secret, and the venue refuses to run without one. A venue that
 * started anyway would look healthy and quietly never hear that a game had
 * ended.
 *
 * Read from configuration today and deliberately behind a class, because where
 * it comes from is going to change. An operator will eventually set and rotate
 * this from a screen rather than a deploy, and everything that asks for it
 * should not have to care.
 */
final class Secrets
{
    /**
     * Every secret currently accepted, newest first.
     *
     * More than one on purpose. Rotating a single shared secret means a moment
     * where one side has changed it and the other has not, and that moment is
     * an outage — so the old one keeps working until it is taken off the list.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $configured = config('streetmesh.venue.secret');

        $secrets = array_values(array_filter(
            array_map(trim(...), is_array($configured) ? $configured : explode(',', (string) $configured)),
            static fn (string $secret): bool => $secret !== '',
        ));

        if ($secrets === []) {
            throw new RuntimeException(
                'No STREETMESH_REALTIME_SECRET is set, so this venue cannot tell a hub from anybody else. '
                .'Set the same value here and where the hub runs.'
            );
        }

        return $secrets;
    }

    /**
     * The one to hand out, which is the newest.
     */
    public function current(): string
    {
        return $this->all()[0];
    }

    /**
     * Whether something a hub presented is one of ours.
     *
     * Compared in constant time and against every accepted secret, because
     * comparing a secret with `===` leaks how much of it was right to anybody
     * patient enough to measure.
     */
    public function accepts(?string $offered): bool
    {
        if ($offered === null || $offered === '') {
            return false;
        }

        $accepted = false;

        foreach ($this->all() as $secret) {
            // Every one of them, every time. Stopping at the first match would
            // make a correct first secret measurably faster than a correct
            // second one.
            $accepted = hash_equals($secret, $offered) || $accepted;
        }

        return $accepted;
    }

    /**
     * Whether this venue has been given one at all.
     *
     * Asked at boot so an operator finds out on deploy rather than the first
     * time a game ends.
     */
    public function configured(): bool
    {
        try {
            $this->all();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
