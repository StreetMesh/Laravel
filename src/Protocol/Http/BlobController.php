<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use StreetMesh\Protocol\BlobScope;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use Throwable;

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
    public function __construct(
        private readonly BlobStore $blobs,
        private readonly Bearer $bearer,
    ) {}

    /**
     * Somebody else putting bytes into a resident's own store.
     *
     * The half of the exercise that did not exist until there was a permission
     * that could describe it. There was deliberately no upload endpoint here
     * while `blob:` was a scope nothing parsed: an endpoint enforcing a
     * permission the consent screen could not describe would have been a
     * permission granted by nobody.
     *
     * The body is the bytes, raw. Not multipart, which is what a browser form
     * produces and what ATProtocol's own `uploadBlob` also declines to take --
     * a multipart part carries a filename and a declared type, and neither is
     * something this reads, so accepting them would only invite somebody to
     * believe they mattered.
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $permission = $this->bearer->in($request);
        } catch (Throwable $refused) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => $refused->getMessage(),
            ], 401);
        }

        /*
         * What the bytes are being kept *for*, which is not decoration. A
         * blob's visibility is looked up from the collection it belongs to, so
         * bytes with no collection would have no answer to who may read them --
         * and the safe default, private, would leave a venue quietly uploading
         * faces nobody can fetch.
         */
        $collection = (string) $request->query('collection', '');

        if ($collection === '') {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'Bytes are kept for a kind of record, and this did not say which.',
            ], 400);
        }

        /*
         * Two permissions, both required, and neither implied by the other.
         *
         * The record scope, because bytes kept for a kind of record this
         * permission may not write are bytes with no record coming for them.
         * And the blob scope below, because "may write chess games" is not
         * "may store a video".
         */
        if (! Scope::permits($permission->scopes(), $collection, Scope::CREATE)) {
            return response()->json([
                'error' => 'insufficient_scope',
                'message' => "That permission does not cover creating a [{$collection}].",
                'scope' => (string) Scope::forRepo([$collection], [Scope::CREATE]),
            ], 403);
        }

        $bytes = $request->getContent();

        if ($bytes === '') {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'There were no bytes to keep.',
            ], 400);
        }

        try {
            /*
             * What they are, decided by looking at them. The permission is
             * checked against that rather than against anything the caller
             * said, for the same reason the store refuses to be told: a caller
             * who could name the type could store a script under permission for
             * a picture, and have it served back from a resident's own origin.
             */
            $mime = $this->blobs->identify($bytes);
        } catch (Throwable $refused) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => $refused->getMessage(),
            ], 400);
        }

        if (! BlobScope::permits($permission->scopes(), $mime)) {
            return response()->json([
                'error' => 'insufficient_scope',
                'message' => "That permission does not cover keeping [{$mime}].",
                'scope' => (string) BlobScope::forTypes([$mime]),
            ], 403);
        }

        try {
            /*
             * Whose bytes these are was decided when somebody approved this,
             * and the request does not get to name them -- exactly as it does
             * not get to name whose records a record goes in.
             */
            $blob = $this->blobs->put((string) $permission->did, $bytes, $collection);
        } catch (Throwable $refused) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => $refused->getMessage(),
            ], 400);
        }

        return response()->json(['blob' => $blob->reference()], 201);
    }

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
             * Readable from anywhere. Only blobs kept for a kind of thing this
             * server publishes are reached at all, so there is nothing here to
             * withhold from one origin and hand to another — and a model that
             * only its own domicile could fetch would be a model nowhere could
             * draw.
             */
            'Access-Control-Allow-Origin' => '*',

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
