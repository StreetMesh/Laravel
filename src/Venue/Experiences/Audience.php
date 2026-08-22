<?php

namespace StreetMesh\Server\Venue\Experiences;

/**
 * Who may look at one of these while it is happening.
 *
 * A privacy decision, and the experience's to make rather than the venue's. A
 * game of chess is a spectator sport and a therapy session is not, and the
 * venue hosting both has no way to tell them apart — so it asks, per gathering,
 * because the answer is not even a property of the *kind* of thing. Two games
 * of chess at the same venue may reasonably differ.
 *
 * Deliberately about watching only. Nothing here grants a way to *act*: taking
 * a chair is a separate question with a separate answer, and an audience that
 * could join in would be a permission rather than a privacy setting.
 *
 * Three answers rather than a boolean, because "public" collapses two genuinely
 * different things — a thing anybody may stumble on, and a thing anybody who
 * has arrived may look at. The middle one is what a venue with a members' room
 * needs, and a boolean cannot say it.
 */
enum Audience: string
{
    /**
     * Anybody at all, including somebody who has never been here.
     *
     * They follow a link and watch. No door, no account, and nothing to press
     * first — a game people can watch is a better thing than a game they
     * cannot, and asking a stranger to name their own server before they may
     * look at a chessboard is a toll nobody would pay.
     */
    case Anybody = 'anybody';

    /** Anybody who has come through the door, whoever they turned out to be. */
    case Visitors = 'visitors';

    /** Only the people with a place at it. */
    case Players = 'players';

    /**
     * Whether this admits somebody, given what is known about them.
     *
     * The comparison lives here rather than at each call site, so that adding a
     * fourth answer is a change to this file instead of a hunt through
     * everything that ever asked.
     *
     * @param  bool  $arrived  they came through the door and the venue knows who they are
     * @param  bool  $seated  they have a place at this gathering
     */
    public function admits(bool $arrived, bool $seated): bool
    {
        return match ($this) {
            self::Anybody => true,
            self::Visitors => $arrived,
            self::Players => $seated,
        };
    }
}
