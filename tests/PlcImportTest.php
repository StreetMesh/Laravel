<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Server\Protocol\Plc\Directory;

/**
 * Bringing identities in from another directory.
 *
 * Written for moving off a directory that ran in Docker, and useful beyond it:
 * mirroring a real identity locally is the same operation.
 *
 * The behaviour worth protecting is that importing is a *replay* rather than a
 * copy. Every operation goes back through the same verification an ordinary
 * submission gets, so an import that succeeds has re-derived each identifier
 * from its genesis and re-checked every signature — which makes it the
 * strongest available check that our reading of the method agrees with the
 * software the real directory runs.
 */
class PlcImportTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.plc.host', true);
    }

    /**
     * A directory somewhere else, holding one identity with a history.
     *
     * @return array{0: FakeNetwork, 1: string}
     */
    private function elsewhere(): array
    {
        $rotation = P256::generate();
        $signing = P256::generate();

        $genesis = Plc::genesis([$rotation], $signing, 'alice.test', 'https://alice.test');
        $did = Plc::did($genesis);
        $renamed = Plc::rename($genesis, $rotation, 'alice.example');

        $log = [
            ['did' => $did, 'operation' => $genesis, 'cid' => 'whatever', 'nullified' => false, 'createdAt' => '2026-01-01T00:00:00Z'],
            ['did' => $did, 'operation' => $renamed, 'cid' => 'whatever', 'nullified' => false, 'createdAt' => '2026-01-02T00:00:00Z'],
        ];

        $network = new FakeNetwork;
        $network->serve('https://old.test/'.$did.'/log/audit', $log);

        return [$network, $did];
    }

    public function test_an_identity_is_brought_in_with_its_whole_history(): void
    {
        [$network, $did] = $this->elsewhere();

        $this->app->instance(Network::class, $network);

        $this->artisan('plc:import', ['did' => [$did], '--from' => 'https://old.test'])
            ->assertSuccessful();

        $directory = $this->app->make(Directory::class);

        $this->assertCount(2, $directory->auditLog($did), 'the history came with it');
        $this->assertSame(['at://alice.example'], $directory->documentFor($did)['alsoKnownAs']);
    }

    /**
     * Replayed rather than copied: the chain is re-linked here, so an import
     * proves the operations verify against our own reading of the method.
     */
    public function test_the_chain_is_relinked_rather_than_trusted(): void
    {
        [$network, $did] = $this->elsewhere();

        $this->app->instance(Network::class, $network);

        $this->artisan('plc:import', ['did' => [$did], '--from' => 'https://old.test'])
            ->assertSuccessful();

        $log = $this->app->make(Directory::class)->auditLog($did);

        $this->assertNull($log[0]['operation']['prev']);
        $this->assertSame($log[0]['cid'], $log[1]['operation']['prev'], 'the second names the first');
        $this->assertNotSame('whatever', $log[0]['cid'], 'the CID is ours, derived rather than taken');
    }

    public function test_bringing_in_somebody_already_here_changes_nothing(): void
    {
        [$network, $did] = $this->elsewhere();

        $this->app->instance(Network::class, $network);

        $this->artisan('plc:import', ['did' => [$did], '--from' => 'https://old.test'])->assertSuccessful();
        $this->artisan('plc:import', ['did' => [$did], '--from' => 'https://old.test'])->assertSuccessful();

        $this->assertCount(2, $this->app->make(Directory::class)->auditLog($did));
    }

    public function test_an_identity_the_other_directory_does_not_have_is_a_failure(): void
    {
        $this->app->instance(Network::class, new FakeNetwork);

        $this->artisan('plc:import', ['did' => ['did:plc:nobodyhere'], '--from' => 'https://old.test'])
            ->assertFailed();
    }

    /**
     * Reading from the directory being written to would import each identity
     * from itself, which does nothing and looks like it worked.
     */
    public function test_it_refuses_to_import_from_itself(): void
    {
        $this->artisan('plc:import', ['did' => ['did:plc:whoever'], '--from' => url('/plc')])
            ->assertFailed();
    }

    public function test_a_server_keeping_no_directory_has_nowhere_to_put_them(): void
    {
        config(['streetmesh.plc.host' => false]);

        $this->artisan('plc:import', ['did' => ['did:plc:whoever'], '--from' => 'https://old.test'])
            ->assertFailed();
    }
}
