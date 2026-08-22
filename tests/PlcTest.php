<?php

namespace StreetMesh\Server\Tests;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\SigningKey;

/**
 * A PLC directory kept by this server.
 *
 * It exists so that a local identity costs nothing to arrange — no container,
 * no compose file, no daemon to remember. What has to survive that convenience
 * is the only thing a directory is trusted for: it must be unable to forge an
 * identity, invent one, or reassign one. Everything below is that property,
 * asked one way at a time.
 */
class PlcTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.plc.host', true);
    }

    /** @return array{0: SigningKey, 1: SigningKey} */
    private function keys(): array
    {
        return [P256::generate(), P256::generate()];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return TestResponse<JsonResponse>
     */
    private function submit(string $did, array $operation): TestResponse
    {
        return $this->postJson('/plc/'.$did, $operation);
    }

    /** An identity, born. */
    private function born(SigningKey $rotation, SigningKey $signing, string $handle = 'alice.test'): string
    {
        $operation = Plc::genesis([$rotation], $signing, $handle, 'https://alice.test');
        $did = Plc::did($operation);

        $this->submit($did, $operation)->assertOk();

        return $did;
    }

    public function test_a_server_that_keeps_no_directory_has_none(): void
    {
        config(['streetmesh.plc.host' => false]);

        $this->getJson('/plc/_health')->assertNotFound();
    }

    public function test_a_directory_says_it_is_there(): void
    {
        $this->getJson('/plc/_health')->assertOk();
    }

    public function test_an_identity_can_be_created_and_resolved(): void
    {
        [$rotation, $signing] = $this->keys();

        $did = $this->born($rotation, $signing);

        $document = $this->getJson('/plc/'.$did)->assertOk()->json();

        $this->assertSame($did, $document['id']);
        $this->assertSame(['at://alice.test'], $document['alsoKnownAs']);
        $this->assertSame($signing->multikey(), $document['verificationMethod'][0]['publicKeyMultibase']);
        $this->assertSame('https://alice.test', $document['service'][0]['serviceEndpoint']);
    }

    public function test_an_identity_nobody_created_does_not_resolve(): void
    {
        $this->getJson('/plc/did:plc:nobodyhaseverbeenhere')->assertNotFound();
    }

    /**
     * The property the whole method rests on: the identifier is the hash of the
     * operation, so the two cannot be chosen independently.
     */
    public function test_an_operation_cannot_be_filed_under_a_name_it_did_not_derive(): void
    {
        [$rotation, $signing] = $this->keys();

        $operation = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');

        $this->submit('did:plc:somethingelseentirely', $operation)
            ->assertStatus(400)
            ->assertSee('creates', escape: false);
    }

    public function test_an_identity_cannot_be_created_twice(): void
    {
        [$rotation, $signing] = $this->keys();

        $operation = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($operation);

        $this->submit($did, $operation)->assertOk();
        $this->submit($did, $operation)->assertStatus(400);
    }

    public function test_a_signature_from_nobody_is_refused(): void
    {
        [$rotation, $signing] = $this->keys();

        $operation = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($operation);

        /*
         * A well-formed operation whose signature belongs to a key it does not
         * name. This is the case a directory exists to refuse.
         */
        $operation['sig'] = rtrim(strtr(base64_encode(
            P256::generate()->sign('anything at all')
        ), '+/', '-_'), '=');

        $this->submit($did, $operation)->assertStatus(400);
    }

    /**
     * Renaming is the operation `did:web` cannot express, and most of the
     * reason for preferring this method: the identifier does not move.
     */
    public function test_an_identity_can_be_renamed_without_changing_its_name(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);

        $this->submit($did, $genesis)->assertOk();

        $renamed = Plc::rename($genesis, $rotation, 'alice.example');

        $this->submit($did, $renamed)->assertOk();

        $document = $this->getJson('/plc/'.$did)->assertOk()->json();

        $this->assertSame($did, $document['id'], 'the identifier is the hash of the genesis and does not move');
        $this->assertSame(['at://alice.example'], $document['alsoKnownAs']);
    }

    /**
     * An operation names exactly the state it was written against, which is
     * what makes the log a chain rather than a pile.
     */
    public function test_an_operation_written_against_a_stale_head_is_refused(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);

        $this->submit($did, $genesis)->assertOk();

        // Two changes, both written against the genesis. The second is stale.
        $first = Plc::rename($genesis, $rotation, 'first.test');
        $second = Plc::rename($genesis, $rotation, 'second.test');

        $this->submit($did, $first)->assertOk();

        $this->submit($did, $second)
            ->assertStatus(400)
            ->assertSee('head', escape: false);
    }

    /**
     * Signed by a key the *previous* operation named, which is what stops a
     * stolen signing key rewriting the rotation list and taking the identity.
     */
    public function test_a_change_signed_by_a_stranger_is_refused(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);

        $this->submit($did, $genesis)->assertOk();

        $stranger = P256::generate();

        $this->submit($did, Plc::rename($genesis, $stranger, 'mallory.test'))
            ->assertStatus(400);
    }

    public function test_an_update_before_the_identity_exists_is_refused(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');

        $this->submit(Plc::did($genesis), Plc::rename($genesis, $rotation, 'alice.example'))
            ->assertStatus(400);
    }

    /**
     * The log is what keeps a directory honest, and it has to be readable by
     * the same client that reads the real one.
     */
    public function test_the_log_is_the_shape_the_client_already_reads(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);

        $this->submit($did, $genesis)->assertOk();

        $replacement = P256::generate();
        $this->submit($did, Plc::rekey($genesis, $rotation, $replacement))->assertOk();

        $log = $this->getJson('/plc/'.$did.'/log/audit')->assertOk()->json();

        $this->assertCount(2, $log);
        $this->assertSame($did, $log[0]['did']);
        $this->assertFalse($log[0]['nullified']);
        $this->assertNull($log[0]['operation']['prev'], 'oldest first');

        /*
         * And the thing the log exists for: a signature made before a rotation
         * still verifies, because the log says which key was current when.
         */
        $history = Plc::keyHistory($log);

        $this->assertCount(2, $history);
        $this->assertSame($signing->multikey(), $history[0]['key']);
        $this->assertSame($replacement->multikey(), $history[1]['key']);
    }

    public function test_the_key_that_was_current_earlier_is_still_answerable(): void
    {
        [$rotation, $signing] = $this->keys();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);

        $this->submit($did, $genesis)->assertOk();

        $before = new DateTimeImmutable;

        $this->travel(1)->hours();

        $this->submit($did, Plc::rekey($genesis, $rotation, P256::generate()))->assertOk();

        $log = $this->getJson('/plc/'.$did.'/log/audit')->json();

        $this->assertSame($signing->multikey(), Plc::keyAt($log, $before));
    }

    public function test_a_log_for_nobody_is_not_an_empty_log(): void
    {
        $this->getJson('/plc/did:plc:nobodyhaseverbeenhere/log/audit')->assertNotFound();
    }
}
