<?php

namespace StreetMesh\Server\Protocol\Blobs;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use StreetMesh\Protocol\Cid;
use StreetMesh\Server\Protocol\Records\Collections;

/**
 * Putting bytes in, and getting them back out.
 *
 * The only way to write a blob, and arranged like `RecordStore` so that the
 * things which make it trustworthy cannot be skipped by accident: the name is
 * computed rather than supplied, and the type is looked at rather than
 * accepted. A caller cannot get either wrong, because a caller does not get to
 * say.
 *
 * The type matters more here than it looks. These bytes are served back from a
 * resident's own hostname, which is the same origin their identity documents
 * are answered from — so an uploader who could choose the content type could
 * serve a script from an address the rest of the network trusts. Sniffing is
 * not politeness about file extensions; it is the whole of what stops that.
 */
final class BlobStore
{
    public function __construct(private readonly Collections $collections) {}

    /**
     * What may be stored at all, and how much of it.
     *
     * Declared rather than open, which is the opposite of the choice
     * `Collections` makes about records and is the right one here. An
     * undeclared record is private and therefore harmless; an undeclared blob
     * would be an arbitrary file served from somebody's identity origin. The
     * safe direction to fail is different, so the default is.
     *
     * @return array<string, int> mime type => ceiling in bytes
     */
    private function allowed(): array
    {
        /** @var array<string, int> $limits */
        $limits = (array) config('streetmesh.blobs.limits', []);

        return $limits;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('streetmesh.blobs.disk', 'local'));
    }

    /**
     * Keep these bytes, and hand back what they are called.
     *
     * Storing the same content twice is not an error and does not write a
     * second copy — the name already says they are the same thing, and a
     * caller re-uploading the picture they already had should get the answer
     * they expect rather than a collision.
     */
    public function put(string $did, string $bytes, string $collection): Blob
    {
        /*
         * Who may read these is decided by what they were kept for, looked up
         * the way a record's is rather than passed in — so there is no input
         * anywhere that could promote a private picture, and no second answer
         * to the question the records table already answers.
         */
        $visibility = $this->collections->visibilityOf($collection);

        $mime = $this->sniff($bytes);
        $ceiling = $this->allowed()[$mime] ?? null;

        if ($ceiling === null) {
            throw new RuntimeException("This server does not store [{$mime}].");
        }

        $size = strlen($bytes);

        if ($size > $ceiling) {
            throw new RuntimeException(
                "That is {$size} bytes, and this server keeps [{$mime}] up to {$ceiling}."
            );
        }

        $cid = (string) Cid::forRaw($bytes);

        $blob = Blob::query()->firstOrNew(['did' => $did, 'cid' => $cid], [
            'mime' => $mime,
            'size' => $size,
            'collection' => $collection,
            'visibility' => $visibility,
            'created_at' => Carbon::now(),
        ]);

        /*
         * The row before the bytes would leave a name pointing at nothing if
         * the disk refused; the bytes before the row leaves a file nobody
         * refers to, which the next write of the same content adopts. Only one
         * of those two failures is recoverable without a human.
         *
         * And the answer is checked rather than assumed. A local disk fails by
         * throwing, which is why this went unnoticed; a bucket fails by
         * returning false — wrong credentials, a bucket that is not there, a
         * region that does not answer — and the caller wraps this in a
         * transaction. Unchecked, a rejected upload commits a record and a row
         * that both name bytes nobody has, and the first anybody hears of it is
         * a face that will not load.
         */
        if ($this->disk()->put($blob->path(), $bytes) === false) {
            throw new RuntimeException(
                'Those bytes were not accepted by this server\'s storage, so nothing was kept.'
            );
        }

        $blob->save();

        return $blob;
    }

    /**
     * One of somebody's blobs, by name.
     *
     * `$asStranger` is the safe default for the same reason it is in
     * `RecordStore::list` — a reader that has not established who it speaks for
     * should see only what anybody may see, so forgetting to say under-serves
     * rather than over-shares.
     */
    public function get(string $did, string $cid, bool $asStranger = true): ?Blob
    {
        $query = Blob::query()->where('did', $did)->where('cid', $cid);

        if ($asStranger) {
            $query->visibleToStrangers();
        }

        return $query->first();
    }

    /**
     * The bytes back, or null if the disk has lost them.
     *
     * Null rather than an exception, because a missing file is a serving
     * problem — somebody's picture does not appear — and not a reason for the
     * page that mentioned them to fail.
     */
    public function bytes(Blob $blob): ?string
    {
        $bytes = $this->disk()->get($blob->path());

        return is_string($bytes) ? $bytes : null;
    }

    public function forget(Blob $blob): void
    {
        $this->disk()->delete($blob->path());

        $blob->delete();
    }

    /**
     * What these bytes actually are.
     *
     * `finfo` reads the content's own signature, so a PNG renamed to `.svg`,
     * or an SVG full of script announced as an image, is answered truthfully.
     */
    private function sniff(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($mime) || $mime === '') {
            throw new RuntimeException('Those bytes could not be identified, so they are not stored.');
        }

        return $mime;
    }
}
