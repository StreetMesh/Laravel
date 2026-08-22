<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use StreetMesh\Server\Protocol\Blobs\BlobStore;

/**
 * Handing back bytes somebody is holding.
 *
 * Unauthenticated, and only ever serving blobs kept for a kind of thing this
 * server publishes. Nothing is decided here: whether a picture is anybody's
 * business was settled when it was stored, from the collection's declaration,
 * and this reads that answer rather than forming its own.
 *
 * Named as ATProtocol names it, for the same reason `createRecord` is — a
 * client that already knows how to fetch a blob from a PDS should not have to
 * learn a second way to fetch one here.
 */
class BlobController
{
    public function __construct(private readonly BlobStore $blobs) {}

    public function get(Request $request): Response
    {
        $blob = $this->blobs->get(
            (string) $request->string('did'),
            (string) $request->string('cid'),
        );

        if ($blob === null) {
            return response('', 404);
        }

        $bytes = $this->blobs->bytes($blob);

        if ($bytes === null) {
            return response('', 404);
        }

        return response($bytes, 200, [
            'Content-Type' => $blob->mime,
            'Content-Length' => (string) strlen($bytes),

            /*
             * The name is the content, so this answer can never become wrong.
             * That is the one case `immutable` is actually true rather than
             * merely convenient.
             */
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$blob->cid.'"',

            /*
             * Nothing here is ever a document, whatever it turns out to be.
             * These bytes come back from an origin that also answers for
             * somebody's identity, so a browser must not be talked into
             * rendering them as one.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
