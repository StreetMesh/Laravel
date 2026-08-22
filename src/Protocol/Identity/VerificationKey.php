<?php

namespace StreetMesh\Server\Protocol\Identity;

/**
 * A key to check a signature against, and how confident we get to be about it.
 *
 * The second part is the whole reason this is an object rather than a string.
 * Asked which key an identity was using at some past moment, `did:plc` can
 * answer from its audit log, and `did:web` cannot answer at all — it publishes
 * a document and no history, so the best it can offer is the key in use now and
 * a hope that nothing has changed.
 *
 * Returning a bare string would make those two answers indistinguishable at the
 * call site, which is exactly where the difference matters: verifying a
 * year-old record against a key that might have been rotated last week is a
 * different act from verifying it against the key that was demonstrably current
 * when it was signed. So the answer carries its own provenance and a caller has
 * to decide what to do about it.
 */
final class VerificationKey
{
    private function __construct(
        public readonly string $multikey,
        public readonly bool $historical,
    ) {}

    /**
     * The key that was demonstrably current at the moment asked about.
     */
    public static function asOf(string $multikey): self
    {
        return new self($multikey, historical: true);
    }

    /**
     * The key in use now, from a method that keeps no history.
     *
     * Correct for anything signed recently and unreliable for anything else,
     * with no way to tell which from here.
     */
    public static function current(string $multikey): self
    {
        return new self($multikey, historical: false);
    }
}
