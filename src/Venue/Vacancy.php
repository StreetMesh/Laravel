<?php

namespace StreetMesh\Server\Venue;

use StreetMesh\Protocol\Network;

/**
 * A name nobody holds, at a domicile that does exist.
 *
 * Somebody types an address at the door and nothing answers for it. Two very
 * different things look like that: a misspelling, and a perfectly good name at a
 * real server that simply nobody has taken. The second is the best moment this
 * venue will ever get to hand somebody an address — they were already trying to
 * use one — and until a name nobody holds started saying so plainly, it could
 * not be told apart from a domicile misbehaving.
 *
 * So the question is asked here, once, and only after resolution has already
 * failed. Nothing on the ordinary path pays for it.
 */
final readonly class Vacancy
{
    public function __construct(private Network $network) {}

    /**
     * The domicile somebody could claim this name at, or nowhere.
     *
     * Nowhere is the ordinary answer and the safe one: a typo, a host that does
     * not exist, a name at somewhere that is not a StreetMesh server at all. The
     * offer is only made when the server on the right of the dot answers for
     * itself and says it keeps repositories.
     */
    public function forHandle(string $handle): ?string
    {
        $rest = strstr(strtolower(trim($handle)), '.');

        if ($rest === false) {
            return null;
        }

        $domicile = substr($rest, 1);

        /*
         * A bare hostname on the right — `alice.test` — is somebody's whole
         * server rather than a name issued by one. There is nothing to claim
         * there and no reason to think anybody is handing out addresses.
         */
        if (! str_contains($domicile, '.')) {
            return null;
        }

        return $this->keepsRepositories($domicile) ? $domicile : null;
    }

    /**
     * Whether that hostname answers for itself as somewhere people live.
     *
     * Its own document, read the same way any stranger would read it. A venue
     * does not declare this, so a name at another venue is correctly no offer at
     * all.
     *
     * What it cannot tell is whether that domicile is *taking* residents —
     * that is the operator's decision and nothing published says it yet, so an
     * offer is a good guess rather than a promise. It is made as an invitation
     * to go and look, not as an assurance of a name.
     */
    private function keepsRepositories(string $host): bool
    {
        $body = $this->network->get('https://'.$host.'/.well-known/did.json');

        if ($body === null) {
            return false;
        }

        $document = json_decode($body, true);

        if (! is_array($document)) {
            return false;
        }

        foreach ((array) ($document['service'] ?? []) as $service) {
            if (is_array($service) && ($service['type'] ?? null) === 'AtprotoPersonalDataServer') {
                return true;
            }
        }

        return false;
    }
}
