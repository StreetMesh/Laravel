<?php

namespace StreetMesh\Server\Venue\Experiences;

use StreetMesh\Server\Venue\Gatherings\Gathering;

/**
 * An experience that has something to write down when it ends.
 *
 * Separate from `Experience` because most things do not. A shop does not
 * conclude, and a screening room has no result — an interface every experience
 * had to implement would be one most of them implemented with an empty method.
 *
 * A venue holds no opinion about what a result is. It knows a gathering
 * finished and it knows which experience the gathering belongs to, and this is
 * how it hands the one to the other.
 */
interface Settles
{
    /**
     * The gathering is over. Do whatever that means here.
     *
     * Called with what the hub reported, which is the only account of what
     * happened that exists — the room is memory and is gone by the time
     * anybody could ask again.
     *
     * May be called more than once for the same gathering: a browser noticing
     * the end and the hub announcing it are two different messengers carrying
     * the same news, and either may arrive first or alone. Doing this twice
     * must not write anything twice.
     *
     * @param  array<string, mixed>  $result
     */
    public function settle(Gathering $gathering, array $result): void;
}
