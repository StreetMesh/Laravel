<?php

namespace StreetMesh\Server\Venue\Gatherings;

use JsonException;
use RuntimeException;
use StreetMesh\Protocol\Network;

/**
 * Asking the hub how something ended.
 *
 * The hub is the referee and the venue is the notary, and this is the one
 * sentence that passes between them. The hub decided what happened; only the
 * venue can sign it into anybody's records, because only the venue holds a key.
 *
 * Asked rather than announced, and the direction matters. A hub that pushed
 * results would be a hub the venue had to authenticate, and the hub holds no
 * credential to authenticate with — it verifies what the venue signed and signs
 * nothing itself. Asking keeps the trust one-way, exactly as the ticket does in
 * the other direction.
 *
 * What a browser can do is say "look now". It cannot say what happened: the
 * answer comes from here, and a game that is not over answers nothing.
 */
final readonly class Results
{
    public function __construct(private Network $network) {}

    /**
     * How a gathering ended, or nothing if it has not.
     *
     * @return array<string, mixed>|null
     */
    public function of(Gathering $gathering): ?array
    {
        $answer = $this->network->get(
            $this->origin().'/result?room='.rawurlencode($gathering->room())
        );

        /*
         * Null covers both "no such room" and "still being played", which the
         * hub deliberately does not distinguish. Neither is a failure and both
         * mean the same thing here: there is nothing to write down yet.
         */
        if ($answer === null || trim($answer) === '') {
            return null;
        }

        try {
            $result = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $notJson) {
            throw new RuntimeException('The hub answered with something that was not a result.', previous: $notJson);
        }

        return is_array($result) && $result !== [] ? $result : null;
    }

    /**
     * Who is actually at each of these right now.
     *
     * The venue knows who sat down; only the hub knows who is still sitting
     * there. A seat outlives a dropped connection on purpose — otherwise an
     * opponent could take your chair while you reconnected — so counting seats
     * counts a history rather than a room.
     *
     * One question for all of them, and a room the hub has no answer for is
     * absent rather than empty: "nobody is there" and "there is no room" are
     * different things to say.
     *
     * An unreachable hub answers nothing at all, and callers show nothing
     * rather than a number they cannot stand behind.
     *
     * @param  iterable<Gathering>  $gatherings
     * @return array<string, array<int, array{name: string, seat: string}>>
     */
    public function at(iterable $gatherings): array
    {
        $rooms = [];

        foreach ($gatherings as $gathering) {
            $rooms[] = 'room='.rawurlencode($gathering->room());
        }

        if ($rooms === []) {
            return [];
        }

        $answer = $this->network->get($this->origin().'/present?'.implode('&', $rooms));

        if ($answer === null) {
            return [];
        }

        try {
            $present = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($present) ? $present : [];
    }

    /**
     * Whether the hub is answering at all.
     *
     * `at()` returns nothing both when no rooms are open and when the hub could
     * not be reached, which is fine for drawing a lobby and dangerous for
     * anything that acts on the answer: a hub having a bad minute would look
     * exactly like every table being empty.
     *
     * So anything that deletes asks this first, and does nothing when the
     * answer is no.
     */
    public function reachable(): bool
    {
        try {
            $origin = $this->origin();
        } catch (RuntimeException $none) {
            /*
             * No hub configured at all, which certainly means it cannot be
             * asked. A venue refuses to serve without one, but that guard is
             * skipped in the console — and a scheduled command should report a
             * misconfigured server rather than crash on it every five minutes.
             */
            return false;
        }

        return $this->network->get($origin.'/build') !== null;
    }

    /**
     * Where the hub answers questions, as opposed to where browsers talk to it.
     *
     * Derived from the one address an operator configures rather than asking
     * for a second one. They are the same server; a websocket scheme and an
     * http scheme are the same host seen from two sides, and two settings that
     * had to agree would eventually not.
     */
    private function origin(): string
    {
        $hub = (string) config('streetmesh.venue.hub');

        if ($hub === '') {
            throw new RuntimeException('No hub is configured, so there is nothing to ask how a game ended.');
        }

        return str_replace(['wss://', 'ws://'], ['https://', 'http://'], rtrim($hub, '/'));
    }
}
