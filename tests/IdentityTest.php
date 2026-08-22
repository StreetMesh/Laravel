<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Protocol\Multikey;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Protocol\Signature;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;

/**
 * A server with an identity of its own, and residents with theirs.
 */
class IdentityTest extends TestCase
{
    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Minting a resident publishes to a directory, so these tests reach the
         * network where they used not to. The fake accepts and remembers, which
         * is what lets a test ask whether something was actually published
         * rather than only whether the code that publishes it ran.
         */
        $this->network = new FakeNetwork;
        $this->app->instance(Network::class, $this->network);
        $this->app->forgetInstance(PlcDirectory::class);
        $this->app->forgetInstance(Identities::class);
    }

    private function identities(): Identities
    {
        return $this->app->make(Identities::class);
    }

    public function test_a_server_has_one_identity_and_makes_it_once(): void
    {
        $first = $this->identities()->forServer();
        $second = $this->identities()->forServer();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Identity::query()->where('is_server', true)->count());
        $this->assertSame('did:web:games.test', $first->did);
    }

    public function test_a_servers_key_actually_signs(): void
    {
        $identity = $this->identities()->forServer();
        $key = $identity->key();

        $signature = $key->sign('what happened');

        $this->assertTrue(Signature::verify($identity->key()->multikey(), 'what happened', $signature));
        $this->assertFalse(Signature::verify($identity->key()->multikey(), 'something else', $signature));
    }

    /**
     * P-256 by default because it is the only curve that works for did:web now
     * and did:plc later. An identity minted on Ed25519 could never move to the
     * method that makes it portable.
     */
    public function test_identities_are_minted_on_a_curve_that_can_move(): void
    {
        $identity = $this->identities()->forServer();

        $this->assertSame('p256', $identity->signing_curve);
        $this->assertSame('p256', Multikey::curveOf($identity->key()->multikey()));
    }

    public function test_keys_are_not_readable_from_the_database(): void
    {
        $identity = $this->identities()->forServer();

        $stored = (string) $this->app['db']->table('streetmesh_identities')
            ->where('id', $identity->id)->value('signing_key');

        // A database dump must not be a set of keys.
        $this->assertNotSame($identity->signing_key, $stored);
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringNotContainsString($identity->key()->multikey(), $stored);
    }

    /**
     * A person is not a place.
     *
     * The server's own identity is `did:web` and is reasonably found where it
     * lives. A resident's is not, because a resident may want a different name
     * next year or a different server, and neither should cost them the records
     * they have already signed. A `did:web` cannot survive either — a `did:web`
     * *is* the address.
     */
    public function test_somebody_who_lives_here_gets_an_identifier_that_is_not_an_address(): void
    {
        ['identity' => $identity] = $this->identities()->forResident('alice.games.test');

        $this->assertStringStartsWith('did:plc:', $identity->did);
        $this->assertStringNotContainsString('alice.games.test', $identity->did);

        // The server keeps the kind of identifier that says where it is.
        $this->assertStringStartsWith('did:web:', $this->identities()->forServer()->did);
    }

    /**
     * Published, not merely minted.
     *
     * A `did:plc` that was never submitted is a name nobody in the world can
     * resolve — and it would look completely correct in this server's database.
     */
    public function test_the_identity_is_put_on_the_record_where_others_can_find_it(): void
    {
        ['identity' => $identity] = $this->identities()->forResident('alice.games.test');

        $this->assertCount(1, $this->network->submitted);
        $this->assertStringEndsWith($identity->did, $this->network->submitted[0]['url']);

        $operation = json_decode($this->network->submitted[0]['body'], true);

        $this->assertSame('plc_operation', $operation['type']);
        $this->assertSame(['at://alice.games.test'], $operation['alsoKnownAs']);
        $this->assertNotEmpty($operation['sig']);

        // Theirs first, this server's second: the order is the order of
        // authority, and it is what lets them overrule us.
        $this->assertCount(2, $operation['rotationKeys']);
    }

    /**
     * The decision that makes "you can leave" true rather than a slogan.
     */
    public function test_a_resident_is_handed_the_key_that_lets_them_leave(): void
    {
        ['identity' => $identity, 'rotationKey' => $rotation] = $this->identities()
            ->forResident('alice.games.test');

        // A key they can actually use, and not the one this server signs with.
        $this->assertTrue(Signature::verify(
            $rotation->multikey(),
            'moving out',
            $rotation->sign('moving out'),
        ));

        $this->assertNotSame($identity->key()->multikey(), $rotation->multikey());

        /*
         * And it is *theirs*. This server holds a rotation key of its own — it
         * has to, or it could never change their handle for them — but a
         * different one, and lower in the order of authority. The distinction
         * is the whole of "you can leave": PLC lets a higher key undo what a
         * lower one did, so whatever this server does with its key, they can
         * overrule with the one they are holding here.
         *
         * The same key in both places would make leaving something this server
         * grants rather than something they do.
         */
        $this->assertNotNull($identity->rotation_key);
        $this->assertNotSame(
            $rotation->multikey(),
            $identity->rotationKey()?->multikey(),
            'the key they keep must not be the key this server keeps',
        );
    }

    public function test_a_handle_is_taken_only_once(): void
    {
        $this->identities()->forResident('alice.games.test');

        $this->expectException(RuntimeException::class);

        $this->identities()->forResident('alice.games.test');
    }

    public function test_identities_are_findable_by_name_and_by_identifier(): void
    {
        ['identity' => $identity] = $this->identities()->forResident('alice.games.test');

        $this->assertSame($identity->id, $this->identities()->byHandle('alice.games.test')?->id);
        $this->assertSame($identity->id, $this->identities()->byHandle('@Alice.Games.Test')?->id);
        $this->assertSame($identity->id, $this->identities()->byDid($identity->did)?->id);
    }

    /**
     * First contact. Somebody holding a signed document knows only which key
     * signed it; this is where they find out what that key is, without an
     * introduction and without permission.
     */
    public function test_a_stranger_can_look_up_who_this_server_is(): void
    {
        $identity = $this->identities()->forServer();

        $document = $this->get('/.well-known/did.json')->assertOk()->json();

        $this->assertSame($identity->did, $document['id']);
        $this->assertSame($identity->keyId(), $document['verificationMethod'][0]['id']);
        $this->assertSame($identity->key()->multikey(), $document['verificationMethod'][0]['publicKeyMultibase']);
        $this->assertSame('Multikey', $document['verificationMethod'][0]['type']);
        $this->assertContains('at://games.test', $document['alsoKnownAs']);
    }

    /**
     * The end of the chain anybody walks to reach this server.
     *
     * Never asserted before, and so it published `https://` with no host after
     * it for as long as it existed — a document that resolved perfectly and led
     * nowhere. Every hop of discovery worked; the last one was empty.
     */
    public function test_the_document_says_where_to_actually_reach_this_server(): void
    {
        $document = $this->get('/.well-known/did.json')->assertOk()->json();

        $this->assertNotEmpty($document['service']);

        foreach ($document['service'] as $service) {
            $this->assertSame(
                'https://games.test',
                $service['serviceEndpoint'],
                'a service endpoint with no host is a document that leads nowhere',
            );
        }
    }

    /**
     * A resident's name is a name under this server's own, so both documents are
     * served for more than one identity and the hostname is what tells them
     * apart. Both answered with the server's identity regardless of who was
     * asked for — so every resident handle resolved to the server, and a venue
     * following one would have been handed the wrong identity entirely.
     */
    public function test_somebody_who_lives_here_resolves_to_their_own_identity(): void
    {
        $resident = $this->identities()->forResident('alice.games.test')['identity'];
        $server = $this->identities()->forServer();

        $this->assertNotSame($server->did, $resident->did);

        $this->get('http://alice.games.test/.well-known/atproto-did')
            ->assertOk()
            ->assertSee($resident->did);

        $document = $this->get('http://alice.games.test/.well-known/did.json')->assertOk()->json();

        $this->assertSame($resident->did, $document['id']);
        $this->assertContains('at://alice.games.test', $document['alsoKnownAs']);
    }

    /**
     * A resident's repository is kept here. Their document has to say so, or a
     * venue would follow their name to a server built out of their own name,
     * where there is nothing.
     */
    public function test_a_residents_document_points_at_the_server_that_holds_their_repository(): void
    {
        $this->identities()->forResident('alice.games.test');

        $document = $this->get('http://alice.games.test/.well-known/did.json')->assertOk()->json();

        $this->assertNotEmpty($document['service']);
        $this->assertSame('https://games.test', $document['service'][0]['serviceEndpoint']);
        $this->assertSame('AtprotoPersonalDataServer', $document['service'][0]['type']);
    }

    public function test_a_stranger_can_resolve_the_name_back_to_the_identity(): void
    {
        $identity = $this->identities()->forServer();

        $this->get('/.well-known/atproto-did')
            ->assertOk()
            ->assertSee($identity->did);
    }

    /**
     * Both directions have to agree. A name pointing at an identity proves only
     * that whoever serves the name says so; the document claiming it back is the
     * identity agreeing. Either alone lets somebody hang a familiar name on a
     * stranger.
     */
    public function test_the_name_and_the_document_agree_with_each_other(): void
    {
        $did = trim($this->get('/.well-known/atproto-did')->getContent());
        $document = $this->get('/.well-known/did.json')->json();

        $this->assertSame($document['id'], $did);
        $this->assertContains('at://'.config('streetmesh.host'), $document['alsoKnownAs']);
    }

    public function test_neither_lookup_needs_an_account(): void
    {
        // A record checkable only by somebody with an account here would be as
        // durable as the arrangement between two parties, rather than outliving
        // it.
        $this->get('/.well-known/did.json')->assertOk();
        $this->get('/.well-known/atproto-did')->assertOk();
    }
}
