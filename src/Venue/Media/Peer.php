<?php

namespace StreetMesh\Server\Venue\Media;

/**
 * Browsers talking directly, which needs no infrastructure and no configuration.
 *
 * Every participant connects to every other, so each of them uploads a copy of
 * their stream per peer. That is ideal for two people and hopeless somewhere
 * past four, which is where the party ceiling comes from — and it keeps a venue
 * runnable by one person on one machine, which is the property this
 * architecture keeps having to defend.
 *
 * Deliberately without a configuration block. An early sketch gave it one, for
 * the addresses it uses to get through a router, and those are a property of
 * peer-to-peer rather than a decision an operator makes — a knob nobody has a
 * reason to turn is one more thing to get wrong before anything works at all.
 *
 * A relay is the answer for the pairs this fails, and it is not built. It is a
 * fallback rather than a prerequisite, and when it arrives it is a driver
 * holding one credential rather than configuration every venue must fill in
 * first. Relayed media is billed to the venue, so that venue's cost would scale
 * with conversation; direct media costs it nothing at all, which is the
 * strongest argument for keeping this the default rather than the fallback.
 */
final class Peer
{
    /**
     * Where a browser goes to find out how it is reachable from outside.
     *
     * Public resolvers, because asking one is a single packet that reveals a
     * public address the far side is about to be told anyway. Two of them, so a
     * blocked or slow one is not a connection that never forms.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ice(): array
    {
        return [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun.cloudflare.com:3478'],
        ];
    }
}
