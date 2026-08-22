<?php

namespace StreetMesh\Protocol\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;

class ClientMetadataTest extends TestCase
{
    private function venue(string ...$scopes): ClientMetadata
    {
        return ClientMetadata::forVenue(
            clientId: 'https://games.test/client-metadata.json',
            clientName: 'Games',
            clientUri: 'https://games.test',
            redirectUris: ['https://games.test/connect/callback'],
            jwksUri: 'https://games.test/jwks.json',
            scopes: array_values($scopes),
        );
    }

    /**
     * The document names itself, and a server checks that against where it
     * fetched it from. Without that a document could claim to be some other
     * client and borrow whatever that client had been trusted with.
     */
    public function test_the_document_names_the_url_it_is_served_from(): void
    {
        $this->assertSame(
            'https://games.test/client-metadata.json',
            $this->venue()->toArray()['client_id'],
        );
    }

    /**
     * Not a preference — a client declaring anything else is asking for bearer
     * tokens, which this profile does not issue.
     */
    public function test_it_asks_for_tokens_bound_to_a_key(): void
    {
        $this->assertTrue($this->venue()->toArray()['dpop_bound_access_tokens']);
    }

    /**
     * A signature rather than a shared secret is what makes this a confidential
     * client, and there is nothing for a domicile to store as a result.
     */
    public function test_it_authenticates_by_signature_and_publishes_no_secret(): void
    {
        $document = $this->venue()->toArray();

        $this->assertSame('private_key_jwt', $document['token_endpoint_auth_method']);
        $this->assertSame('ES256', $document['token_endpoint_auth_signing_alg']);
        $this->assertSame('https://games.test/jwks.json', $document['jwks_uri']);

        $this->assertSame(
            [],
            array_intersect(['client_secret', 'client_secret_expires_at'], array_keys($document)),
            'nothing here may be a secret — this document is public by design',
        );
    }

    /**
     * The claim to be following this profile at all.
     */
    public function test_every_session_asks_for_the_atproto_scope(): void
    {
        $this->assertSame('atproto', $this->venue()->toArray()['scope']);
        $this->assertSame('atproto transition:generic', $this->venue('transition:generic')->toArray()['scope']);
    }

    public function test_asking_for_atproto_twice_does_not_ask_for_it_twice(): void
    {
        $this->assertSame('atproto', $this->venue('atproto')->toArray()['scope']);
    }

    public function test_it_asks_only_for_the_flow_it_uses(): void
    {
        $document = $this->venue()->toArray();

        $this->assertSame(['authorization_code', 'refresh_token'], $document['grant_types']);
        $this->assertSame(['code'], $document['response_types']);
    }

    /**
     * Every URL here is fetched by strangers over the open web. One reachable
     * in the clear invites whoever is in the middle to answer for us.
     */
    public function test_nothing_here_may_be_reachable_in_the_clear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientMetadata::forVenue(
            clientId: 'http://games.test/client-metadata.json',
            clientName: 'Games',
            clientUri: 'https://games.test',
            redirectUris: ['https://games.test/connect/callback'],
            jwksUri: 'https://games.test/jwks.json',
        );
    }

    public function test_a_redirect_in_the_clear_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientMetadata::forVenue(
            clientId: 'https://games.test/client-metadata.json',
            clientName: 'Games',
            clientUri: 'https://games.test',
            redirectUris: ['http://games.test/connect/callback'],
            jwksUri: 'https://games.test/jwks.json',
        );
    }

    public function test_a_client_with_nowhere_to_come_back_to_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientMetadata::forVenue(
            clientId: 'https://games.test/client-metadata.json',
            clientName: 'Games',
            clientUri: 'https://games.test',
            redirectUris: [],
            jwksUri: 'https://games.test/jwks.json',
        );
    }

    /**
     * Published at a URL of its own rather than inline, so rotating a key is
     * replacing a document's contents rather than reissuing the identity that
     * names it.
     */
    public function test_a_key_set_publishes_public_halves_under_a_name(): void
    {
        $key = P256::generate();

        $set = ClientMetadata::keySet(['now' => Jwk::forP256($key)]);

        $this->assertCount(1, $set['keys']);
        $this->assertSame('now', $set['keys'][0]['kid']);
        $this->assertSame('sig', $set['keys'][0]['use']);
        $this->assertSame('ES256', $set['keys'][0]['alg']);
        $this->assertSame('EC', $set['keys'][0]['kty']);
        $this->assertArrayNotHasKey('d', $set['keys'][0], 'a private half must never reach a key set');
    }

    public function test_a_key_set_carries_every_key_it_is_given(): void
    {
        $set = ClientMetadata::keySet([
            'retiring' => Jwk::forP256(P256::generate()),
            'now' => Jwk::forP256(P256::generate()),
        ]);

        $this->assertSame(['retiring', 'now'], array_column($set['keys'], 'kid'));
    }
}
