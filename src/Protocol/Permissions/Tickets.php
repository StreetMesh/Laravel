<?php

namespace StreetMesh\Server\Protocol\Permissions;

use RuntimeException;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Identity\Identities;

/**
 * A permission slip to sit somewhere, for the realtime half of this server.
 *
 * Everything hard has already happened here: a federated address was resolved,
 * a delegation was checked, somebody was seated. The realtime half can do none
 * of that and should never learn how — so it is told the answer, signed with
 * the key this server already publishes, and has only a signature to check.
 *
 * Here rather than in the venue package, though only a venue mints one, because
 * a ticket is a format two independent implementations have to agree on
 * exactly: PHP signs it and TypeScript checks it, across base58, a multicodec
 * prefix, a compressed curve point and a raw signature pair. Anything two
 * implementations must agree about is protocol. Deciding *who* may sit where
 * remains the venue's business entirely.
 *
 * That is what keeps the join path free of shared secrets. The realtime process
 * holds no credential, cannot impersonate this server, and cannot assert
 * anything back: everything it knows arrived signed by somebody else.
 *
 * Short-lived on purpose. A ticket is good for long enough to open a websocket
 * and not long enough to be worth stealing — and because it names one room, a
 * stolen one opens one door rather than the building.
 */
final class Tickets
{
    public const LIFETIME_SECONDS = 60;

    public function __construct(
        private readonly Identities $identities,
        private readonly Attestations $attestations,
    ) {}

    /**
     * A way in for somebody the venue cannot name.
     *
     * Watching a public gathering asks nothing of anybody: no door, no account,
     * nothing pressed first. So there is no delegation to mint from, and the
     * venue is asserting something weaker than usual — not "this is so-and-so"
     * but "somebody may look at this", which is all a room needs to let them.
     *
     * The subject is invented per ticket and means nothing anywhere else. It
     * has to be there because a room identifies occupants by it, and it has to
     * differ between watchers because a room treats a second arrival under one
     * subject as the same person returning — one shared identifier would have
     * every watcher throwing the last one out.
     *
     * No seat, and that is not an oversight. This admits somebody to look, and
     * the room's own rules do the rest: chess already refuses a move from
     * anybody whose ticket seats them nowhere.
     *
     * @param  array<int, string>  $taken  every seat the venue has filled
     */
    public function watcher(string $room, array $taken = []): string
    {
        $identity = $this->identities->forServer();

        return $this->attestations->issue([
            'sub' => 'guest:'.bin2hex(random_bytes(16)),
            'name' => __('Somebody watching'),

            'room' => $room,
            'seat' => '',
            'taken' => array_values(array_unique($taken)),

            /*
             * Never. Being in a party means holding a permission somebody's own
             * server issued, and a watcher is precisely somebody who has not
             * been asked for one. Present so the two ticket shapes do not
             * differ in their keys.
             */
            'party' => '',

            'exp' => now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp(),
            'jti' => bin2hex(random_bytes(16)),
        ], $identity->key(), $identity->keyId());
    }

    /**
     * @param  string  $room  the room being joined, compared rather than trusted
     *                        at the other end
     * @param  array<int, string>  $taken  every seat this venue has filled, which
     *                                     is a thing only the venue knows
     * @param  string  $party  the room name of the party this visitor is in, or
     *                         empty for somebody here on their own
     */
    public function mint(
        Delegation $visitor,
        string $room,
        string $seat = '',
        array $taken = [],
        string $party = '',
    ): string {
        if ($visitor->did === null) {
            throw new RuntimeException('Somebody who has not been seated cannot be given a ticket.');
        }

        $identity = $this->identities->forServer();

        return $this->attestations->issue([
            /*
             * Who, and what to call them. The name is this venue's word rather
             * than the visitor's, because a name a person types is a name they
             * can choose to be somebody else's.
             */
            'sub' => $visitor->did,
            'name' => $visitor->handle,

            'room' => $room,
            'seat' => $seat,

            /*
             * Not this visitor's business, and sent anyway.
             *
             * Which seats are filled is the venue's record, and the realtime
             * half has no way to learn it: it sees connections, and a
             * connection is not a seat. Anything that turns on there being two
             * players — resigning, most of all — would otherwise have to ask
             * whether both are online this second, and answer "no" to somebody
             * whose opponent has closed their tab.
             *
             * It rides on the ticket because that is the only thing this server
             * signs on the way in. A room that asked the venue instead would be
             * a room holding a credential, and the whole arrangement here is
             * that it holds none.
             */
            'taken' => array_values(array_unique($taken)),

            /*
             * Whether this person is here with other people, and which other
             * people.
             *
             * Carried so a room can *show* it. Somebody in a party has their
             * voice superseded by the party's, which makes them present and
             * unhearable to everybody else in the room — and a person who
             * cannot be told that is a person talking to a wall without knowing
             * why. It is also the only thing making a private channel legible
             * to the people it is being used beside.
             *
             * The party's room name rather than a flag, because it is the same
             * name the party's own room is joined under and two spellings of
             * one thing is one of them going stale.
             */
            'party' => $party,

            'exp' => now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp(),
            'jti' => bin2hex(random_bytes(16)),
        ], $identity->key(), $identity->keyId());
    }
}
