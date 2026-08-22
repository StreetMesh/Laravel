<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use StreetMesh\Server\Protocol\Blobs\BlobStore;

/**
 * Handing bytes to a stranger, and refusing to.
 *
 * The endpoint decides nothing. Everything it appears to decide was settled
 * when the blob was stored, from the declaration of the collection it was kept
 * for — so what these check is that it reads that answer rather than forming
 * one of its own.
 */
class BlobTest extends TestCase
{
    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            strict: true,
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.blobs.disk', 'blobs');
        $app['config']->set('filesystems.disks.blobs', ['driver' => 'local', 'root' => storage_path('app/blobs')]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('blobs');
    }

    private function kept(string $collection): string
    {
        return $this->app->make(BlobStore::class)->put(self::ALICE, $this->png(), $collection)->cid;
    }

    /**
     * @return TestResponse<Response>
     */
    private function ask(string $did, string $cid): TestResponse
    {
        return $this->get(url('/xrpc/com.atproto.sync.getBlob').'?did='.urlencode($did).'&cid='.urlencode($cid));
    }

    public function test_a_public_blob_comes_back_as_its_own_bytes(): void
    {
        $answer = $this->ask(self::ALICE, $this->kept('com.streetmesh.games.chess'))->assertOk();

        $this->assertSame($this->png(), $answer->getContent());
        $answer->assertHeader('Content-Type', 'image/png');
    }

    /**
     * The name is the content, so this answer can never go stale — which is the
     * one case `immutable` is true rather than merely convenient.
     */
    public function test_it_is_cacheable_forever_and_says_so(): void
    {
        $cid = $this->kept('com.streetmesh.games.chess');

        $this->ask(self::ALICE, $cid)
            ->assertHeader('ETag', '"'.$cid.'"')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    }

    /**
     * These come back from the origin somebody's identity documents are served
     * from, so a browser must never be talked into treating them as a page.
     */
    public function test_nothing_served_here_can_be_read_as_a_document(): void
    {
        $this->ask(self::ALICE, $this->kept('com.streetmesh.games.chess'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function test_a_blob_kept_for_something_private_is_not_served(): void
    {
        $this->ask(self::ALICE, $this->kept('com.streetmesh.messages.direct'))->assertNotFound();
    }

    /** Somebody else's name over the right content is still nobody's blob. */
    public function test_the_subject_has_to_match(): void
    {
        $this->ask('did:plc:ewvi7nxzyoun6zhxrhs64oiz', $this->kept('com.streetmesh.games.chess'))
            ->assertNotFound();
    }

    public function test_a_name_this_server_is_not_holding_is_absent(): void
    {
        $this->ask(self::ALICE, 'bafkreihdwdcefgh4dqkjv67uzcmw7ojee6xedzdetojuzjevtenxquvyku')
            ->assertNotFound();
    }

    /** A row whose file has gone is a missing picture, not a server error. */
    public function test_a_row_whose_bytes_are_gone_is_absent_rather_than_fatal(): void
    {
        $cid = $this->kept('com.streetmesh.games.chess');

        Storage::disk('blobs')->deleteDirectory('streetmesh');

        $this->ask(self::ALICE, $cid)->assertNotFound();
    }
}
