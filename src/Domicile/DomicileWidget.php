<?php

namespace StreetMesh\Server\Domicile;

use StreetMesh\Server\Protocol\Capabilities\Widget;

/**
 * What this capability puts on somebody's home page, if the operator wants it.
 */
final class DomicileWidget implements Widget
{
    public function name(): string
    {
        return 'domicile.records';
    }

    public function title(): string
    {
        return 'Your records';
    }

    public function view(): string
    {
        return 'domicile::widget';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
