<?php

namespace StreetMesh\Server\Venue\Chat;

use RuntimeException;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;

/**
 * Saying something in a space.
 *
 * The whole model is one sentence: post a message to a space, and everybody in
 * that space sees it. A lobby, a table and a party are the same kind of thing —
 * somewhere you can be — so they are the same kind of thing here.
 *
 * Text layers rather than superseding, which is the one place a party behaves
 * differently from how its audio does. You cannot listen to two conversations,
 * so party voice replaces the room's; you can perfectly well read two, and a
 * party member cut off from the room's chat would miss whatever everybody
 * around them is reacting to.
 */
final class Chat
{
    public function __construct(
        private readonly Parties $parties,
    ) {}

    /**
     * Say something.
     *
     * The speaker's name is taken from their delegation rather than from the
     * request, for the same reason a ticket's is: a name somebody types is a
     * name they can choose to be somebody else's.
     */
    public function say(string $space, Delegation $who, string $body): Message
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('There is nothing there to say.');
        }

        if (mb_strlen($body) > Message::LONGEST) {
            throw new RuntimeException('That is longer than anybody is going to read.');
        }

        $this->refuseUnlessIn($space, $who);

        return Message::create([
            'space' => $space,
            'did' => (string) $who->did,
            'name' => (string) $who->handle,
            'body' => $body,
        ]);
    }

    /**
     * Whether somebody may read a space at all.
     *
     * Only asked about parties, and that is deliberate rather than lazy. A
     * party is private by construction — the only way into one is to have been
     * asked — so talking into somebody else's would be a real leak, and it is
     * the one space here whose membership this class can settle on its own.
     *
     * Every other space is one of this venue's own screens, and who may be
     * looking at those is decided at the door they came through: the lobby is
     * behind `visitor`, and a gathering's chat is on a page a gathering already
     * decided to show them. Re-deciding it here would be a second opinion about
     * the same question, and two of those is one of them being wrong.
     */
    public function readableBy(string $space, ?Delegation $who): bool
    {
        $party = $this->partyIn($space);

        if ($party === null) {
            return true;
        }

        return $who !== null && $this->parties->memberOf($party, $who) !== null;
    }

    private function refuseUnlessIn(string $space, Delegation $who): void
    {
        if (! $this->readableBy($space, $who)) {
            throw new RuntimeException('You are not in that party.');
        }
    }

    /**
     * The party a space names, if it names one.
     *
     * Null for every other kind of space, which is most of them.
     */
    private function partyIn(string $space): ?Party
    {
        if (! str_starts_with($space, Party::ROOM.'/')) {
            return null;
        }

        return Party::query()
            ->keyed(substr($space, strlen(Party::ROOM) + 1))
            ->whereNull('disbanded_at')
            ->first();
    }
}
