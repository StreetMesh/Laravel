<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreetMesh\Protocol\Handle;
use StreetMesh\Protocol\Network;

/**
 * The one behavior the vectors cannot pin, because it is about two answers
 * agreeing rather than about bytes.
 *
 * Also the reason Network is an interface: none of this needs a server, a
 * fixture directory, or an internet connection to be true.
 */
class HandleTest extends TestCase
{
    private function network(?string $wellKnown = null, ?string $txt = null): Network
    {
        return new class($wellKnown, $txt) implements Network
        {
            public function __construct(
                private readonly ?string $wellKnown,
                private readonly ?string $txt,
            ) {}

            public function get(string $url): ?string
            {
                return $this->wellKnown;
            }

            /**
             * @return array<int, string>
             */
            public function txt(string $name): array
            {
                return $this->txt === null ? [] : ['did='.$this->txt];
            }

            /**
             * Resolving an identity never writes, so reaching this is a bug
             * rather than a case to stub.
             *
             * @param  array<string, string>  $headers
             * @return array{status: int, body: string}
             */
            public function post(string $url, string $body, array $headers = []): array
            {
                throw new \LogicException('Nothing being tested here writes to the network.');
            }
        };
    }

    public function test_a_handle_resolves_from_the_well_known_endpoint(): void
    {
        $handle = new Handle($this->network(wellKnown: 'did:plc:z72i7hdynmk6r22z27h6tvur'));

        $this->assertSame('did:plc:z72i7hdynmk6r22z27h6tvur', $handle->resolve('alice.example.com'));
    }

    /**
     * The escape hatch for a server that cannot serve a host per subject.
     */
    public function test_a_handle_falls_back_to_dns(): void
    {
        $handle = new Handle($this->network(txt: 'did:plc:fallback'));

        $this->assertSame('did:plc:fallback', $handle->resolve('alice.example.com'));
    }

    public function test_an_unresolvable_handle_is_refused_rather_than_guessed(): void
    {
        $this->expectException(RuntimeException::class);

        (new Handle($this->network()))->resolve('nobody.example.com');
    }

    public function test_the_leading_at_people_type_is_accepted(): void
    {
        $handle = new Handle($this->network(wellKnown: 'did:plc:abc'));

        $this->assertSame('did:plc:abc', $handle->resolve('@Alice.Example.com '));
    }

    /**
     * A handle pointing at an identity is only half of a claim. Without the
     * identity claiming the handle back, anyone able to publish a name could
     * hang it on a stranger.
     */
    public function test_verification_needs_the_identity_to_claim_the_handle_back(): void
    {
        $handle = new Handle($this->network(wellKnown: 'did:plc:abc'));

        $this->assertSame('did:plc:abc', $handle->verify(
            'alice.example.com',
            fn (string $did): array => ['alsoKnownAs' => ['at://alice.example.com']],
        ));

        $this->expectException(RuntimeException::class);

        $handle->verify(
            'alice.example.com',
            fn (string $did): array => ['alsoKnownAs' => ['at://somebody.else.com']],
        );
    }

    public function test_an_identity_claiming_nothing_does_not_verify(): void
    {
        $this->expectException(RuntimeException::class);

        (new Handle($this->network(wellKnown: 'did:plc:abc')))
            ->verify('alice.example.com', fn (string $did): array => []);
    }
}
