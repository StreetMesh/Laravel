<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\PublishedAvatar;

/**
 * A convention, and the hardening around it.
 *
 * The convention is one line. Everything else here is about the fact that a
 * handle arrives over the wire — from a room ticket, from a poll, from another
 * server's answer — and is then turned into an address a browser is sent to.
 * Every test below is a string somebody could put in that field.
 */
class PublishedAvatarTest extends TestCase
{
    public function test_a_handle_is_a_hostname_and_that_is_the_whole_idea(): void
    {
        $this->assertSame(
            'https://collegeman.stme.sh/avatar/icon',
            PublishedAvatar::iconAt('collegeman.stme.sh'),
        );
    }

    public function test_the_two_paths_are_different_things(): void
    {
        $this->assertNotSame(PublishedAvatar::ICON, PublishedAvatar::MODEL);
    }

    /** Written the way people write it to each other. */
    public function test_a_leading_at_sign_is_not_part_of_the_name(): void
    {
        $this->assertSame(
            'https://alice.home.test/avatar/icon',
            PublishedAvatar::iconAt('@Alice.Home.Test'),
        );
    }

    /**
     * The one thing this must never do is send a browser somewhere a stranger
     * chose.
     */
    #[DataProvider('nothingUsable')]
    public function test_anything_that_is_not_a_handle_is_refused(?string $given, string $why): void
    {
        $this->assertNull(PublishedAvatar::iconAt($given), $why);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function nothingUsable(): array
    {
        return [
            'nobody' => [null, 'there is nobody to ask about'],
            'empty' => ['', 'an empty name is not a host'],
            'blank' => ['   ', 'nor is whitespace'],
            'a bare word' => ['localhost', 'a handle is a name under a domain, and this is a machine on the LAN'],
            'a path' => ['evil.example/../alice.home.test', 'a handle is a host and nothing after it'],
            'credentials' => ['alice.home.test:8080@evil.example', 'the host here is evil.example, which is not who was named'],
            'a port' => ['alice.home.test:8080', 'a handle names a server, not a socket on one'],
            'a query' => ['alice.home.test?at=evil.example', 'nothing rides along behind a name'],
            'a fragment' => ['alice.home.test#evil.example', 'nor in front of one'],
            'a trailing slash' => ['alice.home.test/', 'which parses as a path, and a handle has none'],
            'a scheme alone' => ['https://', 'nothing survives it'],
            'a bare scheme word' => ['https', 'which parses as a host and resolves to somebody'],
        ];
    }

    /**
     * A scheme already on the front is removed rather than prefixed, or
     * `https://` + `https://alice.example` parses to a host of `https`.
     */
    public function test_a_handle_somebody_pasted_as_a_url_still_names_them(): void
    {
        $this->assertSame(
            'https://alice.home.test/avatar/icon',
            PublishedAvatar::iconAt('https://alice.home.test'),
        );
    }

    /** Never plain http, whatever was handed in. */
    public function test_it_is_always_https(): void
    {
        $this->assertStringStartsWith('https://', (string) PublishedAvatar::iconAt('http://alice.home.test'));
    }
}
