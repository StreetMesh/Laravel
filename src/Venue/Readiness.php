<?php

namespace StreetMesh\Server\Venue;

/**
 * Whether a venue can open, and what to say if it cannot.
 *
 * Two things it cannot work without, and the same reason for both: the failure
 * without either is silence. Results never arrive, nothing errors, and the
 * venue looks perfectly well — so the answer has to be loud, and it has to come
 * before anybody sits down rather than when the first game ends.
 *
 * Separated from the service provider that throws it because a provider boots
 * once, in an application, and refuses to be asked hypothetical questions. What
 * is actually being decided here is small enough to hold in one hand and worth
 * being sure of.
 */
final readonly class Readiness
{
    public function __construct(
        private bool $isVenue,
        private bool $hasSecret,
        private ?string $hub,
        private bool $parties = false,
        private int $partySize = 0,
    ) {}

    /**
     * Things that will work, and not the way somebody asked for.
     *
     * Separate from `missing` because these are not reasons to stay shut. A
     * venue with a party size it cannot honour still opens — it just opens
     * having quietly done something other than what its configuration says,
     * and quietly is the part worth fixing.
     *
     * @return array<int, string>
     */
    public function concerns(): array
    {
        if (! $this->isVenue || ! $this->parties) {
            return [];
        }

        $concerns = [];

        if ($this->partySize > Parties\Parties::MESH_CEILING) {
            $concerns[] = 'STREETMESH_VENUE_PARTY_SIZE is '.$this->partySize.', and parties here hold '
                .Parties\Parties::MESH_CEILING.'. Media between people in a party is peer-to-peer, so every '
                .'participant sends a copy of their stream to every other and it stops working somewhere past '
                .'four. The larger number is being ignored.';
        }

        return $concerns;
    }

    /**
     * What is missing, or null if nothing is.
     *
     * A server that did not say it is a venue is never missing anything. It
     * installed this package because it is built from the same codebase as one
     * that did, and it owes nobody an explanation of how it would talk to a hub
     * it does not have.
     */
    public function missing(): ?string
    {
        if (! $this->isVenue) {
            return null;
        }

        // A hub holds no key of its own, so a shared secret is the only way a
        // venue can tell one from anybody else.
        if (! $this->hasSecret) {
            return 'This venue has no STREETMESH_REALTIME_SECRET, so it cannot tell a hub from anybody else. '
                .'Set one here and wherever the hub runs — the same value in both places.';
        }

        // And a venue is where people gather, which takes somewhere to gather.
        if (blank($this->hub)) {
            return 'This server says it is a venue but has no STREETMESH_HUB, so nothing it offers can open. '
                .'Point it at a hub, or set STREETMESH_VENUE=false if this server is not one.';
        }

        return null;
    }
}
