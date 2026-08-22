<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreetMesh\Protocol\AuthorizationServer;
use StreetMesh\Protocol\Network;

/**
 * Most of what this class does is refuse things, so most of this is refusals.
 *
 * The chain is followed across servers that have never met, over documents
 * nobody signs, so every hop is somewhere a wrong answer could be substituted
 * for a right one. Walking it correctly is the easy half and is checked against
 * the live network by `bin/check-discovery.php`; declining to walk it when
 * something is off is the half that needs saying out loud here.
 */
class AuthorizationServerTest extends TestCase
{
    /**
     * @param  array<string, array<string, mixed>>  $documents  keyed by URL
     */
    private function network(array $documents): Network
    {
        return new class($documents) implements Network
        {
            /**
             * @param  array<string, array<string, mixed>>  $documents
             */
            public function __construct(private readonly array $documents) {}

            public function get(string $url): ?string
            {
                return isset($this->documents[$url])
                    ? (string) json_encode($this->documents[$url])
                    : null;
            }

            /**
             * @return array<int, string>
             */
            public function txt(string $name): array
            {
                return [];
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    private function wellFormed(array $overrides = []): array
    {
        return [
            'https://pds.example/.well-known/oauth-protected-resource' => [
                'authorization_servers' => ['https://auth.example'],
            ],
            'https://auth.example/.well-known/oauth-authorization-server' => [
                'issuer' => 'https://auth.example',
                'pushed_authorization_request_endpoint' => 'https://auth.example/oauth/par',
                'authorization_endpoint' => 'https://auth.example/oauth/authorize',
                'token_endpoint' => 'https://auth.example/oauth/token',
                'require_pushed_authorization_requests' => true,
                'client_id_metadata_document_supported' => true,
                'dpop_signing_alg_values_supported' => ['ES256'],
                ...$overrides,
            ],
        ];
    }

    /**
     * @return callable(string): array<string, mixed>
     */
    private function livingAt(string $pds): callable
    {
        return fn (string $did): array => [
            'alsoKnownAs' => ['at://alice.example'],
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => $pds,
            ]],
        ];
    }

    public function test_a_did_walks_all_the_way_to_somewhere_to_ask(): void
    {
        $server = AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed()),
        );

        $this->assertSame('https://auth.example', $server->issuer);
        $this->assertSame('https://auth.example/oauth/par', $server->pushedAuthorizationRequest);
        $this->assertSame('https://auth.example/oauth/authorize', $server->authorization);
        $this->assertSame('https://auth.example/oauth/token', $server->token);
    }

    /**
     * The point of asking the resource rather than assuming it: a host may keep
     * the records and let somebody else grant permission over them.
     */
    public function test_the_guardian_need_not_be_the_host(): void
    {
        $server = AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed()),
        );

        $this->assertNotSame('https://pds.example', $server->issuer);
    }

    /**
     * The one substitution nothing else catches. These documents are fetched
     * over plain TLS with nothing signed inside them, so a server naming
     * somebody else as the issuer would have tokens minted in that name.
     */
    public function test_a_server_cannot_issue_in_somebody_elses_name(): void
    {
        $this->expectException(RuntimeException::class);

        AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed(['issuer' => 'https://somebody.else'])),
        );
    }

    public function test_a_server_that_does_not_require_par_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed(['require_pushed_authorization_requests' => false])),
        );
    }

    /**
     * Without this a venue would have to be registered in advance, which is the
     * arrangement the whole chain exists to avoid.
     */
    public function test_a_server_that_will_not_read_a_client_document_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed(['client_id_metadata_document_supported' => false])),
        );
    }

    public function test_an_identity_with_nowhere_to_keep_records_goes_no_further(): void
    {
        $this->expectException(RuntimeException::class);

        AuthorizationServer::forAccount(
            'did:plc:abc',
            fn (string $did): array => ['service' => []],
            $this->network($this->wellFormed()),
        );
    }

    public function test_a_resource_naming_no_guardian_goes_no_further(): void
    {
        $documents = $this->wellFormed();
        $documents['https://pds.example/.well-known/oauth-protected-resource'] = ['authorization_servers' => []];

        $this->expectException(RuntimeException::class);

        AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($documents),
        );
    }

    /**
     * "We cannot sign for this server" and "this server refused us" are
     * different answers, and a client that cannot tell them apart reports a
     * missing capability as a rejection.
     */
    public function test_it_says_which_signatures_a_server_will_take(): void
    {
        $server = AuthorizationServer::forAccount(
            'did:plc:abc',
            $this->livingAt('https://pds.example'),
            $this->network($this->wellFormed(['dpop_signing_alg_values_supported' => ['ES256', 'RS256']])),
        );

        $this->assertTrue($server->accepts('ES256'));
        $this->assertFalse($server->accepts('EdDSA'));
    }
}
