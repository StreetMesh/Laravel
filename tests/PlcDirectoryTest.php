<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;

/**
 * The two ways to publish something permanent by accident.
 *
 * Both of these are written after making the mistake rather than before. A
 * package test suite inherited the public directory as a default and minted
 * about thirty real identities for `alice.home.test` — permanent, global, and
 * resolvable by nobody. Nothing about the configuration looked wrong, because
 * a URL that is already correct-looking is not something you go and check.
 */
class PlcDirectoryTest extends TestCase
{
    private function network(): Network
    {
        return new class implements Network
        {
            public function get(string $url): ?string
            {
                return null;
            }

            public function txt(string $name): array
            {
                return [];
            }

            /**
             * @param  array<string, string>  $headers
             * @return array{status: int, body: string}
             */
            public function post(string $url, string $body, array $headers = []): array
            {
                return ['status' => 200, 'body' => ''];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function genesis(string $endpoint): array
    {
        return Plc::genesis(
            rotationKeys: [P256::generate()],
            signingKey: P256::generate(),
            handle: 'alice.'.parse_url($endpoint, PHP_URL_HOST),
            serviceEndpoint: $endpoint,
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function localHosts(): array
    {
        return [
            'herd and valet' => ['https://home.test'],
            'bonjour' => ['https://home.local'],
            'localhost itself' => ['http://localhost:8000'],
            'by address' => ['http://127.0.0.1:8000'],
            'documentation' => ['https://home.example'],
        ];
    }

    #[DataProvider('localHosts')]
    public function test_a_host_that_exists_on_one_laptop_is_not_published_to_everybody(string $endpoint): void
    {
        $directory = new PlcDirectory($this->network(), PlcDirectory::DEFAULT);
        $operation = $this->genesis($endpoint);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/public directory/');

        $directory->submit(Plc::did($operation), $operation);
    }

    /**
     * The guard is about the *public* record. A directory of your own is where
     * development is supposed to happen, and it may hold whatever you like.
     */
    public function test_your_own_directory_will_take_anything(): void
    {
        $directory = new PlcDirectory($this->network(), 'https://plc.test');
        $operation = $this->genesis('https://home.test');

        $directory->submit(Plc::did($operation), $operation);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Empty is not "use the usual one". An operator who has not said where
     * identities are published has not decided, and picking the global
     * registry on their behalf is the one answer that cannot be undone.
     */
    public function test_no_directory_configured_is_a_refusal_rather_than_a_default(): void
    {
        $directory = new PlcDirectory($this->network(), '');
        $operation = $this->genesis('https://home.example.org');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no sensible default/');

        $directory->submit(Plc::did($operation), $operation);
    }
}
