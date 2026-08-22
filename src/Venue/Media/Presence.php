<?php

namespace StreetMesh\Server\Venue\Media;

use Illuminate\Support\Facades\Cache;

/**
 * Who is actually at a party right now, as opposed to who belongs to one.
 *
 * Membership is a fact in the database and outlives everything: it survives a
 * closed laptop, a flat battery and a week away. This is the other question —
 * whose browser is here this second — and it is the only one a handshake can be
 * built on, because you cannot offer a camera to somebody who is not looking.
 *
 * It used to be the realtime half's answer, and that was the wrong place for
 * it. A room hands out an identity of its own and takes it away when the socket
 * drops, so a moment of bad signal read to everybody else as one person leaving
 * and a stranger arriving: every connection torn down and rebuilt, and a
 * reconnection that could kick the very session it was reconnecting. None of
 * that is about who is in the room. It is about the room having opinions on
 * identity, which is the venue's business.
 *
 * So a browser names itself, and this remembers the name for a few seconds at a
 * time. Nothing is issued, so nothing can be taken away; a browser that goes
 * quiet simply stops being mentioned, and one that comes back says the same
 * name it said before and is where it was.
 *
 * It rides on the poll the handshake was already making, which is why adding it
 * costs no request, no socket and nothing to keep alive. See `Mailbox`, whose
 * discipline this follows exactly.
 */
final class Presence
{
    /**
     * How long a browser stays listed without saying anything.
     *
     * Five missed polls at the settled pace. Long enough that a slow request or
     * a browser briefly throttled in a background tab does not read as somebody
     * leaving the room, short enough that a laptop lid closing is noticed while
     * anybody still cares.
     *
     * An ordinary departure does not wait for this — leaving, or closing the
     * page, says so at once.
     */
    public const FRESH_SECONDS = 5;

    /**
     * The backstop, for the entry as a whole.
     *
     * Freshness is decided by the timestamps inside rather than by this, so
     * that it can be tested by moving the clock rather than by waiting, and so
     * it does not depend on what a cache driver does about expiry.
     *
     * The framework's clock rather than the machine's, for the same reason: a
     * test that cannot move time can only prove this by sleeping through it.
     */
    private const TTL_SECONDS = 60;

    /**
     * How many browsers may be listed at one party.
     *
     * A connection names itself, so this is the ceiling on what one member can
     * write. The venue caps a party far below it; this is only here so that the
     * answer to somebody being inventive is a full list rather than an
     * unbounded one.
     */
    private const DEPTH = 32;

    /**
     * Say that a browser is here, and answer who else is.
     *
     * One acquisition of the lock for both halves, because they are one
     * question: everybody polls constantly, and reading a list that a
     * simultaneous write is halfway through would show somebody arriving twice
     * or not at all.
     *
     * `resumed` says this browser's own entry had lapsed — it was away long
     * enough that everybody else has already forgotten it. That is worth
     * knowing on the way back, because every connection it thought it had is
     * gone and rebuilding them is cheaper than discovering it one failed
     * handshake at a time.
     *
     * @return array{present: array<int, array{id: string, name: string, space: string}>, resumed: bool}
     */
    public function seen(string $space, string $id, string $name, string $where = ''): array
    {
        $present = [];
        $resumed = false;

        $this->under($this->key($space), function (array $listed) use ($id, $name, $where, &$present, &$resumed): array {
            $now = now()->getTimestamp();

            $resumed = ! isset($listed[$id]) || ($now - $listed[$id]['seen']) > self::FRESH_SECONDS;

            $listed[$id] = [
                'name' => $name,
                'space' => mb_substr($where, 0, 200),
                'seen' => $now,
            ];

            $listed = $this->fresh($listed, $now);

            foreach ($listed as $who => $entry) {
                if ($who === $id) {
                    continue;
                }

                $present[] = [
                    'id' => (string) $who,
                    'name' => (string) $entry['name'],
                    'space' => (string) $entry['space'],
                ];
            }

            return $listed;
        });

        return ['present' => $present, 'resumed' => $resumed];
    }

    /**
     * Say that a browser has gone, without waiting to be missed.
     *
     * What keeps leaving instant. Everything else here is a timeout, and a
     * timeout is what you fall back on when nobody said goodbye.
     */
    public function gone(string $space, string $id): void
    {
        $this->under($this->key($space), function (array $listed) use ($id): array {
            unset($listed[$id]);

            return $listed;
        });
    }

    /**
     * @param  array<string, array{name: string, space: string, seen: int}>  $listed
     * @return array<string, array{name: string, space: string, seen: int}>
     */
    private function fresh(array $listed, int $now): array
    {
        $kept = array_filter(
            $listed,
            fn (array $entry): bool => ($now - (int) $entry['seen']) <= self::FRESH_SECONDS,
        );

        /* Newest kept, on the reasoning that the oldest is the likeliest to be
           somebody who has already gone. */
        if (count($kept) > self::DEPTH) {
            uasort($kept, fn (array $a, array $b): int => $b['seen'] <=> $a['seen']);

            $kept = array_slice($kept, 0, self::DEPTH, true);
        }

        return $kept;
    }

    /**
     * @param  callable(array<string, array{name: string, space: string, seen: int}>): array<string, array{name: string, space: string, seen: int}>  $change
     */
    private function under(string $key, callable $change): void
    {
        /*
         * Everybody at the party is writing to this same entry, once a second,
         * for as long as they are there. Read-then-write without the lock loses
         * whoever was unlucky, and what that looks like is a person flickering
         * in and out of the room.
         */
        Cache::lock($key.':writing', 5)->block(3, function () use ($key, $change): void {
            /** @var array<string, array{name: string, space: string, seen: int}> $listed */
            $listed = Cache::get($key, []);

            Cache::put($key, $change($listed), self::TTL_SECONDS);
        });
    }

    /**
     * One entry for the whole party rather than one per browser, because
     * listing who is here means reading them all and no cache driver will scan
     * its own keys to find them.
     */
    private function key(string $space): string
    {
        return 'streetmesh:present:'.sha1($space);
    }
}
