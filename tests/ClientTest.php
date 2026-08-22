<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Identity\Identities;

/**
 * The two documents that stand in for registering.
 *
 * A domicile that has never heard of this venue reads the first to learn what
 * it is and the second to check what it signed. Nothing is exchanged, agreed or
 * stored beforehand on either side — which is the property being defended here,
 * so most of these check that nothing private leaked into something public.
 */
class ClientTest extends TestCase
{
    /**
     * The route that receives somebody coming back, which in a real deployment
     * belongs to the venue package. Stubbed here because this document's whole
     * job is to publish that address, and a package that names a route it does
     * not own should fail loudly when nothing owns it.
     */
    protected function defineRoutes($router): void
    {
        $router->get('connect/callback', fn () => '')->name('venue.callback');
    }

    private function identities(): Identities
    {
        return $this->app->make(Identities::class);
    }

    /**
     * The document names the address it is served from, and a server compares
     * the two. Without that, a document could claim to be some other client and
     * borrow whatever that client had been trusted with.
     */
    public function test_the_document_is_its_own_address(): void
    {
        $document = $this->get('/client-metadata.json')->assertOk()->json();

        $this->assertSame(url('/client-metadata.json'), $document['client_id']);
    }

    public function test_it_asks_for_tokens_bound_to_a_key(): void
    {
        $document = $this->get('/client-metadata.json')->json();

        $this->assertTrue($document['dpop_bound_access_tokens']);
        $this->assertSame('private_key_jwt', $document['token_endpoint_auth_method']);
    }

    /**
     * This document is fetched by strangers. Anything secret in it would be
     * secret from nobody.
     */
    public function test_nothing_secret_is_published(): void
    {
        $document = $this->get('/client-metadata.json')->json();

        foreach (['client_secret', 'client_secret_expires_at', 'registration_access_token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $document);
        }
    }

    public function test_it_points_at_keys_a_stranger_can_fetch(): void
    {
        $document = $this->get('/client-metadata.json')->json();

        $this->assertSame(url('/jwks.json'), $document['jwks_uri']);

        $this->get('/jwks.json')->assertOk();
    }

    /**
     * The half that verifies, never the half that signs. `d` is the private
     * scalar, and a key set carrying one would hand this server away.
     */
    public function test_the_key_set_publishes_only_public_halves(): void
    {
        $keys = $this->get('/jwks.json')->assertOk()->json()['keys'];

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey('d', $key);
            $this->assertSame('EC', $key['kty']);
            $this->assertSame('sig', $key['use']);
        }
    }

    /**
     * One key, one name. A stranger checking a signature has to arrive at the
     * same key the DID document publishes, or the two documents describe two
     * different servers.
     */
    public function test_the_published_key_is_the_one_this_server_signs_with(): void
    {
        $key = $this->identities()->forServer()->key();

        $this->assertInstanceOf(P256::class, $key);

        $published = $this->get('/jwks.json')->json()['keys'][0];

        $this->assertSame(Jwk::forP256($key)->toArray()['x'], $published['x']);
        $this->assertSame(Jwk::forP256($key)->toArray()['y'], $published['y']);
    }

    public function test_every_session_asks_for_the_atproto_scope(): void
    {
        $this->assertStringContainsString(
            'atproto',
            $this->get('/client-metadata.json')->json()['scope'],
        );
    }

    /**
     * Both are read by servers that have no account here and never will.
     */
    public function test_neither_document_needs_an_account(): void
    {
        $this->get('/client-metadata.json')->assertOk();
        $this->get('/jwks.json')->assertOk();
    }
}
