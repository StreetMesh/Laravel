<?php

namespace StreetMesh\Server\Venue;

use StreetMesh\Server\Protocol\Capabilities\Widget;

/**
 * What this capability puts on somebody's home page, if the operator wants it.
 */
final class VenueWidget implements Widget
{
    public function name(): string
    {
        return 'venue.experiences';
    }

    public function title(): string
    {
        return "What's on";
    }

    public function view(): string
    {
        return 'venue::widget';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
