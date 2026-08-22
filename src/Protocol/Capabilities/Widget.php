<?php

namespace StreetMesh\Server\Protocol\Capabilities;

/**
 * A panel on somebody's home page.
 *
 * The home page is the one surface where installed capabilities genuinely
 * overlap — a person signed in to a server that is both a home and a gathering
 * place has business with both, on one screen. Everything else a capability
 * offers has a screen of its own and nothing to negotiate.
 *
 * So capabilities do not build the home page. They offer pieces of one, and the
 * operator decides which appear and in what order — which is the difference
 * between a server somebody runs and a product they receive.
 */
interface Widget
{
    /**
     * A stable name, used in configuration.
     *
     * Stable because an operator's arrangement refers to it. Renaming one
     * silently drops it from every home page that mentioned it.
     */
    public function name(): string;

    public function title(): string;

    /**
     * The view that draws it.
     */
    public function view(): string;

    /**
     * Whatever that view needs.
     *
     * @return array<string, mixed>
     */
    public function data(): array;
}
