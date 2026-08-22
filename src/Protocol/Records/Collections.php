<?php

namespace StreetMesh\Server\Protocol\Records;

use InvalidArgumentException;

/**
 * Which kinds of record this server publishes, and what becomes of the rest.
 *
 * Visibility belongs to the kind of record, not to the record. A chess result is
 * public because chess results are public; a message is private because messages
 * are private. Deciding it per record would mean there is an input somewhere
 * saying whether this one is private, and an input can be wrong, forged, or
 * flipped by a bug in a form. So there is no such input, here or anywhere.
 *
 * A collection nobody has declared is **private**, not refused — a correction to
 * a rule that was wrong in an instructive way.
 *
 * Refusing was defended as protection against a typo: a mistyped collection name
 * becoming a kind of record nobody meant to create. That holds for records this
 * server writes itself, where a typo is our own bug. It does not hold for a
 * record arriving from somewhere else, where the name is not ours to mistype and
 * a resident approved it *by name* before anything could be written under it.
 *
 * And it had a cost that outweighed it. A domicile would have had to be
 * configured for chess in advance in order to receive a chess result, so a venue
 * could only settle records to servers whose operators had already heard of it.
 * That is not federation — it is two operators agreeing privately, which is the
 * arrangement this project exists to argue against.
 *
 * The asymmetry that actually mattered survives intact. Publishing cannot be
 * undone — a record replicated out of a public collection cannot be recalled —
 * so the failure that must never happen is a private thing becoming public.
 * Defaulting to private cannot cause it. Defaulting to public would.
 *
 * So this list means "what this server publishes" rather than "what this server
 * will accept", which is a more honest thing for an operator to be deciding.
 */
final class Collections
{
    /**
     * @param  array<string, string>  $declared  collection NSID => visibility
     */
    public function __construct(private readonly array $declared = []) {}

    public function knows(string $collection): bool
    {
        return isset($this->declared[$collection]);
    }

    public function visibilityOf(string $collection): string
    {
        /*
         * Undeclared means private. A server can hold a kind of record it has
         * never been told about — somebody who lives here agreed to it — and
         * holding it is not the same as publishing it.
         */
        $visibility = $this->declared[$collection] ?? Record::PRIVATE;

        return match ($visibility) {
            Record::PUBLIC, Record::PRIVATE => $visibility,
            default => throw new InvalidArgumentException(
                "Collection [{$collection}] is declared [{$visibility}], which is neither public nor private."
            ),
        };
    }

    public function isPublic(string $collection): bool
    {
        return $this->visibilityOf($collection) === Record::PUBLIC;
    }

    /**
     * Every collection a stranger is allowed to read, which is what a listing
     * of somebody's repository may contain.
     *
     * @return array<int, string>
     */
    public function public(): array
    {
        return array_keys(array_filter(
            $this->declared,
            fn (string $visibility): bool => $visibility === Record::PUBLIC,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->declared;
    }
}
