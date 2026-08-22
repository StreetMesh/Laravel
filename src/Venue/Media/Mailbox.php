<?php

namespace StreetMesh\Server\Venue\Media;

use Illuminate\Support\Facades\Cache;

/**
 * Where two browsers leave each other the notes that let them connect directly.
 *
 * Peer-to-peer media still needs somewhere to do the handshake: each side has
 * to learn the other's addresses and what it can decode. That exchange is a few
 * small messages and then it is over for good — after it, the audio and video
 * go straight between the two browsers and this server never sees a frame.
 *
 * It lives here rather than in the room, and that was learned the hard way. It
 * is not gameplay; the room's transport is tuned for small frequent messages
 * and caps one at 4KB, which is fine for a move and about half of what a video
 * offer needs — so enabling a camera closed the socket. The venue already knows
 * who is in which party, which is the only question that needs answering to
 * carry a note.
 *
 * Nothing here is durable. A note not collected within a couple of minutes
 * belongs to a connection nobody is waiting for any more.
 */
final class Mailbox
{
    private const TTL_SECONDS = 120;

    /**
     * How many notes may be waiting for one connection.
     *
     * A peer that never collects would otherwise accumulate everything the
     * other side ever tried, and a stalled handshake is retried rather than
     * queued.
     */
    private const DEPTH = 64;

    /**
     * Leave a note for one connection in one space.
     *
     * Addressed to a connection rather than to a person, because that is what a
     * peer connection is between. The same human with a laptop and a phone is
     * two peers and needs two handshakes, and a note delivered to whichever of
     * them collected first would be an offer answered by the wrong browser.
     *
     * @param  array<string, mixed>  $note
     */
    public function post(string $space, string $from, string $to, array $note): void
    {
        $this->under($this->key($space, $to), function (array $waiting) use ($from, $note): array {
            $waiting[] = ['from' => $from, 'data' => $note];

            return array_slice($waiting, -self::DEPTH);
        });
    }

    /**
     * Take everything waiting, leaving the box empty.
     *
     * @return array<int, array<string, mixed>>
     */
    public function drain(string $space, string $for): array
    {
        $collected = [];

        $this->under($this->key($space, $for), function (array $waiting) use (&$collected): array {
            $collected = $waiting;

            return [];
        });

        return $collected;
    }

    /**
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $change
     */
    private function under(string $key, callable $change): void
    {
        /*
         * Both people are writing to each other at once during a handshake, so
         * read-then-write without this loses notes exactly when it matters.
         */
        Cache::lock($key.':writing', 5)->block(3, function () use ($key, $change): void {
            Cache::put($key, $change(Cache::get($key, [])), self::TTL_SECONDS);
        });
    }

    /**
     * Hashed, because a connection identifier is somebody else's string and a
     * cache key is not the place to find out what characters it contains.
     */
    private function key(string $space, string $recipient): string
    {
        return 'streetmesh:signals:'.sha1($space).':'.sha1($recipient);
    }
}
