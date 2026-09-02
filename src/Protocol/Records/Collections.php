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
     * Collection NSID => visibility.
     *
     * @var array<string, string>
     */
    private readonly array $declared;

    /**
     * The collections whose records are somebody's own claim rather than a
     * third party's statement about them.
     *
     * @var array<int, string>
     */
    private readonly array $claims;

    /**
     * Two shapes, because almost every collection only has one thing to say.
     *
     * A visibility on its own is the ordinary case and stays the ordinary case:
     * `'com.example.thing' => Record::PUBLIC`. A collection with more to
     * declare says so in full:
     *
     *     'com.streetmesh.actor.avatar' => [
     *         'visibility' => Record::PUBLIC,
     *         'attested' => false,
     *     ],
     *
     * Normalised here rather than everywhere it is read, so that widening what
     * an operator may declare did not widen what the rest of this class has to
     * cope with.
     *
     * @param  array<string, string|array{visibility?: string, attested?: bool}>  $declared
     */
    public function __construct(array $declared = [])
    {
        $visibility = [];
        $claims = [];

        foreach ($declared as $collection => $declaration) {
            if (is_array($declaration)) {
                $visibility[$collection] = (string) ($declaration['visibility'] ?? Record::PRIVATE);

                if (($declaration['attested'] ?? true) === false) {
                    $claims[] = $collection;
                }

                continue;
            }

            $visibility[$collection] = $declaration;
        }

        $this->declared = $visibility;
        $this->claims = $claims;
    }

    public function knows(string $collection): bool
    {
        return isset($this->declared[$collection]);
    }

    /**
     * Does a record of this kind carry somebody else's signed statement?
     *
     * True for almost everything, and that default is the careful direction. A
     * chess result is a venue saying who won, and the signature is the whole
     * reason the record is worth holding after the venue has shut down; taking
     * the readable fields instead of the signed ones would make it worth
     * nothing and look identical.
     *
     * False for the few kinds of thing nobody is in a position to attest to. An
     * avatar is the first: nobody can witness what somebody looks like, there
     * is no third party who could, and a signature over one would be a
     * signature over an opinion. Such a record is stored as the claim itself.
     *
     * This does not decide whether a signature is *required in transit* -- it
     * always is, and the endpoint always checks it. It decides what is kept.
     */
    public function attests(string $collection): bool
    {
        return ! in_array($collection, $this->claims, strict: true);
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
