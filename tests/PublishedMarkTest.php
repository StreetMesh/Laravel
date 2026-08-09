<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\PublishedMark;

/**
 * Where a server publishes what it looks like.
 *
 * A convention rather than a negotiation, which is what lets a domicile draw a
 * venue it has never heard of without fetching, checking or believing anything.
 * The address is built from a name that arrived over the wire, so the tests
 * that matter most here are the ones about names that are not names.
 */
final class PublishedMarkTest extends TestCase
{
    public function test_a_host_publishes_a_light_and_a_dark_drawing(): void
    {
        $at = PublishedMark::at('tabletop.streetmesh.com');

        $this->assertSame('https://tabletop.streetmesh.com/mark.svg', $at['light']);
        $this->assertSame('https://tabletop.streetmesh.com/mark-dark.svg', $at['dark']);
    }

    /**
     * HTTPS and a bare host, whatever arrived. A permission screen must never
     * send somebody's browser to an address a stranger chose.
     */
    public function test_only_the_host_survives(): void
    {
        $at = PublishedMark::at('Games.Example:8080/../elsewhere');

        $this->assertSame('https://games.example/mark.svg', $at['light']);
    }

    #[DataProvider('notHosts')]
    public function test_a_name_that_is_not_a_host_publishes_nothing(string $given): void
    {
        $this->assertNull(PublishedMark::at($given));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notHosts(): array
    {
        return [
            'nothing' => [''],
            'spaces' => ['   '],
            'a slash' => ['/'],
            'a scheme alone' => ['https://'],
            'a scheme and nothing' => ['http:///'],
        ];
    }

    public function test_nobody_at_all_publishes_nothing(): void
    {
        $this->assertNull(PublishedMark::at(null));
    }
}
