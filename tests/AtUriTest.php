<?php

namespace StreetMesh\Protocol\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\AtUri;

class AtUriTest extends TestCase
{
    private const ADDRESS = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/com.streetmesh.games.chess/3mqcp5qjdfs26';

    public function test_an_address_names_a_subject_a_kind_and_one_record(): void
    {
        $uri = AtUri::parse(self::ADDRESS);

        $this->assertSame('did:plc:z72i7hdynmk6r22z27h6tvur', $uri->authority);
        $this->assertSame('com.streetmesh.games.chess', $uri->collection);
        $this->assertSame('3mqcp5qjdfs26', $uri->rkey);
        $this->assertTrue($uri->isRecord());
        $this->assertSame(self::ADDRESS, (string) $uri);
    }

    public function test_an_address_may_name_a_whole_collection(): void
    {
        $uri = AtUri::parse('at://did:plc:abc/com.streetmesh.games.chess');

        $this->assertFalse($uri->isRecord());
        $this->assertSame('at://did:plc:abc/com.streetmesh.games.chess', (string) $uri);
    }

    public function test_an_address_may_name_only_a_subject(): void
    {
        $uri = AtUri::parse('at://did:plc:abc');

        $this->assertNull($uri->collection);
        $this->assertSame('at://did:plc:abc', (string) $uri);
    }

    public function test_it_is_built_up_a_part_at_a_time(): void
    {
        $uri = AtUri::make('did:plc:z72i7hdynmk6r22z27h6tvur')
            ->collection('com.streetmesh.games.chess')
            ->record('3mqcp5qjdfs26');

        $this->assertSame(self::ADDRESS, (string) $uri);
        $this->assertTrue($uri->isRecord());
    }

    /**
     * The authority is a DID rather than a host, which is the entire reason
     * this is not an https URL: an address that named a server would stop being
     * true the moment its subject moved.
     */
    public function test_an_address_survives_its_subject_moving(): void
    {
        $before = AtUri::parse(self::ADDRESS);
        $after = AtUri::parse(self::ADDRESS);

        $this->assertSame((string) $before, (string) $after);
        $this->assertStringNotContainsString('http', (string) $before);
    }

    public function test_a_key_without_a_collection_names_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AtUri::make('did:plc:abc', null, '3mqcp5qjdfs26');
    }

    public function test_something_that_is_not_an_address_is_refused(): void
    {
        $this->assertNull(AtUri::tryParse('https://example.com/records/1'));
        $this->assertNull(AtUri::tryParse('at://did:plc:abc/a/b/c'));
    }
}
