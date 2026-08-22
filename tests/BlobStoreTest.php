<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use StreetMesh\Protocol\Cid;
use StreetMesh\Server\Protocol\Blobs\BlobStore;

/**
 * The four things that make this a store rather than an upload folder.
 *
 * Each one is a decision a caller is not allowed to make, and each is checked
 * because the whole value of the arrangement is that it cannot be sidestepped
 * by somebody in a hurry.
 */
class BlobStoreTest extends TestCase
{
    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private const BOB = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

    /** A real one-pixel PNG. Sniffing is the point, so the bytes have to be. */
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

    private function store(): BlobStore
    {
        return $this->app->make(BlobStore::class);
    }

    /**
     * The name is the content, computed here.
     *
     * Nowhere in the signature is there anywhere to put a name, which is the
     * strongest form this promise can take.
     */
    public function test_a_blob_is_named_by_hashing_it(): void
    {
        $blob = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');

        $this->assertSame((string) Cid::forRaw($this->png()), $blob->cid);

        // Under `raw`, not `dag-cbor`. The two are the same length and differ
        // in one byte, so nothing but an assertion catches this.
        $this->assertStringStartsWith('bafkrei', $blob->cid);
    }

    public function test_the_type_is_what_the_bytes_are_and_not_what_anybody_said(): void
    {
        $blob = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');

        $this->assertSame('image/png', $blob->mime);
    }

    /**
     * A type nobody declared is refused rather than stored quietly.
     *
     * The opposite of the rule for record collections, and deliberately: these
     * bytes come back from the same origin somebody's identity is answered
     * from, so an unknown one is a file of a stranger's choosing on a trusted
     * address.
     */
    public function test_an_undeclared_type_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->store()->put(self::ALICE, '<svg xmlns="http://www.w3.org/2000/svg"/>', 'com.streetmesh.games.chess');
    }

    public function test_something_too_large_is_refused(): void
    {
        $this->app['config']->set('streetmesh.blobs.limits', ['image/png' => 10]);

        $this->expectException(RuntimeException::class);

        $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');
    }

    /**
     * Who may read a blob is the collection's answer, not a caller's.
     *
     * The same rule the records table follows, which is the point — a second,
     * subtly different answer to "who may see this" is how the two drift apart.
     */
    public function test_visibility_comes_from_what_the_bytes_were_kept_for(): void
    {
        $public = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');
        $private = $this->store()->put(self::BOB, $this->png(), 'com.streetmesh.messages.direct');

        $this->assertSame('public', $public->visibility);
        $this->assertSame('private', $private->visibility);

        $this->assertNotNull($this->store()->get(self::ALICE, $public->cid));
        $this->assertNull($this->store()->get(self::BOB, $private->cid));

        // Held, though, and readable by somebody who has said who they are.
        $this->assertNotNull($this->store()->get(self::BOB, $private->cid, asStranger: false));
    }

    /**
     * Two people holding the same picture hold two of it.
     *
     * Otherwise either of them deleting theirs would take the other's away.
     */
    public function test_the_same_bytes_under_two_subjects_are_two_blobs(): void
    {
        $hers = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');
        $his = $this->store()->put(self::BOB, $this->png(), 'com.streetmesh.games.chess');

        $this->assertSame($hers->cid, $his->cid);
        $this->assertNotSame($hers->id, $his->id);
        $this->assertNotSame($hers->path(), $his->path());

        $this->store()->forget($hers);

        $this->assertNull($this->store()->get(self::ALICE, $hers->cid));
        $this->assertSame($this->png(), $this->store()->bytes($his));
    }

    /** Storing what is already there is not a second copy and not a failure. */
    public function test_storing_the_same_bytes_twice_is_the_same_blob(): void
    {
        $once = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');
        $again = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');

        $this->assertSame($once->id, $again->id);
    }

    public function test_the_bytes_come_back(): void
    {
        $blob = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');

        $this->assertSame($this->png(), $this->store()->bytes($blob));
        $this->assertSame(strlen($this->png()), $blob->size);
    }

    /**
     * A disk that refuses is a failure, not a silent success.
     *
     * The local disk this was written against fails by throwing, so nothing
     * caught that `put` answers `false` on a bucket — wrong credentials, a
     * bucket that is not there. Unchecked, and inside the transaction the
     * caller opens, that commits a record and a row which both name bytes
     * nobody has.
     */
    public function test_a_disk_that_refuses_the_bytes_keeps_nothing(): void
    {
        $this->app['config']->set('filesystems.disks.blobs', [
            'driver' => 'local',
            'root' => '/dev/null/there-is-no-such-place',
            'throw' => false,
        ]);

        // setUp faked this one, and a faked disk is resolved and held.
        Storage::forgetDisk('blobs');

        $this->expectException(RuntimeException::class);

        $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');
    }

    /**
     * A row whose file has gone is a picture that does not appear, not a page
     * that fails.
     */
    public function test_bytes_the_disk_has_lost_are_absent_rather_than_fatal(): void
    {
        $blob = $this->store()->put(self::ALICE, $this->png(), 'com.streetmesh.games.chess');

        Storage::disk('blobs')->delete($blob->path());

        $this->assertNull($this->store()->bytes($blob));
    }
}
