<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StreetMesh\Protocol\AtUri;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Identity\DidResolver;
use StreetMesh\Server\Protocol\Records\Collections;
use StreetMesh\Server\Protocol\Records\RecordStore;
use StreetMesh\Server\Protocol\Records\RecordWritten;
use Throwable;

/**
 * Somebody else writing a record into a resident's own store.
 *
 * The end of the whole exercise. A venue asked, a resident agreed, and this is
 * where that agreement is spent — the venue writes the finished game into the
 * player's records, on the player's server, and then has nothing further to do
 * with it. The record is not the venue's copy of what happened; it is the
 * player's, and it outlives the venue.
 *
 * Three things are checked and none of them is "is this venue trustworthy":
 * whether the token is live, whether the key presenting it is the key it was
 * issued to, and whether what was granted covers what is being attempted.
 */
final class RepoController
{
    public function __construct(
        private readonly Bearer $bearer,
        private readonly RecordStore $records,
        private readonly Collections $collections,
        private readonly BlobStore $blobs,
        private readonly Attestations $attestations,
        private readonly DidResolver $resolver,
    ) {}

    public function create(Request $request): JsonResponse
    {
        try {
            $permission = $this->bearer->in($request);
        } catch (Throwable $refused) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => $refused->getMessage(),
            ], 401);
        }

        $collection = (string) $request->input('collection');

        /*
         * The scope decides, not the venue's identity and not this server's
         * opinion of it. A venue granted `action=create` on chess games cannot
         * write anything else, however well it is thought of.
         */
        if (! Scope::permits($permission->scopes(), $collection, Scope::CREATE)) {
            return response()->json([
                'error' => 'insufficient_scope',
                'message' => "That permission does not cover creating a [{$collection}].",
                'scope' => (string) Scope::forRepo([$collection], [Scope::CREATE]),
            ], 403);
        }

        $value = $request->input('record');

        if (! is_array($value)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'A record has to be an object.',
            ], 400);
        }

        /*
         * Anything written on somebody's behalf has to be signed by whoever is
         * writing it. That is the whole difference between a record they hold
         * and a record they merely received: a received one is worth what the
         * sender's continued existence is worth, and a signed one can be
         * checked by a stranger years after the venue has shut down.
         *
         * So the fields are taken from inside the signature rather than from
         * beside it. A venue cannot send readable values that differ from what
         * it signed, because the readable values are not read.
         */
        try {
            $attested = $this->attestations->verify((string) ($value['attestation'] ?? ''));
        } catch (Throwable $refused) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'A record written on somebody\'s behalf must carry a signature this server '
                    .'can check: '.$refused->getMessage(),
            ], 400);
        }

        if (! $this->answersTo($attested->issuer, $permission->client_id)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => "That statement was signed by [{$attested->issuer}], which is not the client writing it.",
            ], 400);
        }

        $did = (string) $permission->did;

        /*
         * What is kept, which is not the same question as what was checked.
         *
         * Almost everything here is an attestation, and for those the fields
         * are taken from inside the signature and the compact form is kept
         * beside them — that is what a stranger can check years later.
         *
         * A few kinds of record are somebody's own claim about themselves, and
         * an avatar is the first. Nobody attests to a face; storing one wrapped
         * as though somebody had would put a signature over an opinion, and
         * would make a face written by a venue a different shape from the same
         * face written by the resident at their own settings screen. So the
         * claim is stored as itself.
         *
         * The signature is not skipped for those. It was required, checked, and
         * matched against the client a moment ago — it is how these bytes are
         * known not to have been altered on the way. What it is not is part of
         * the record.
         */
        $value = $this->collections->attests($collection)
            ? $attested->toRecord()
            : [...$attested->claims, 'writtenBy' => $attested->issuer];

        /*
         * A record may name bytes, and the bytes have to already be here.
         *
         * `$link` is a name rather than a reference, and a name is satisfied by
         * whatever happens to be at it — nothing later would notice. So a
         * record pointing at a blob this server does not hold is refused now,
         * while there is somebody to tell.
         */
        $missing = $this->unresolved($value, $did);

        if ($missing !== []) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'That record refers to bytes this server is not holding: '
                    .implode(', ', $missing).'. Upload them first.',
            ], 400);
        }

        /*
         * Written into the granting resident's own store, and nowhere else. The
         * request does not get to name whose records these are: that was
         * decided when somebody approved this, and letting a parameter override
         * it would let one person's permission write into another's store.
         */
        try {
            $record = $this->records->put($did, $collection, $value);
        } catch (Throwable $refused) {
            /*
             * Most likely a collection this server has not declared. That is a
             * refusal rather than a fault — the caller asked for something this
             * domicile does not keep — and answering 500 would tell them to try
             * again later for a request that will never work.
             */
            return response()->json([
                'error' => 'invalid_request',
                'message' => $refused->getMessage(),
            ], 400);
        }

        /*
         * Whatever keeps an index of this kind of record can now bring it up to
         * date. Nothing here knows which parts of this server those are, or
         * whether any of them care about a collection it may never have seen.
         */
        event(new RecordWritten($record));

        return response()->json([
            'uri' => (string) AtUri::make($record->did, $record->collection, $record->rkey),
            'cid' => $record->cid,
        ], 201);
    }

    /**
     * Is the identity that signed this the same party the resident let in?
     *
     * Without this a venue with permission to add records could relay somebody
     * else's genuine signed statement into a resident's store — not a forgery,
     * since it verifies, but not something that resident agreed to receive
     * either.
     *
     * The client is named by a URL and the signer by a DID, so the tie is the
     * host: `did:web:games.test` is that host by construction, and any other
     * method has to claim the host in its document. That is the same
     * bidirectional rule handles use, applied to the one link that matters
     * here.
     */
    private function answersTo(string $issuer, string $clientId): bool
    {
        $host = parse_url($clientId, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        if ($issuer === (string) Did::forHost($host)) {
            return true;
        }

        try {
            $document = $this->resolver->document($issuer);
        } catch (Throwable) {
            return false;
        }

        return in_array('at://'.$host, (array) ($document['alsoKnownAs'] ?? []), strict: true);
    }

    /**
     * Every blob a record names that this server is not holding.
     *
     * Recursive because a reference may be anywhere in a record's shape --
     * nothing here knows what any particular collection looks like, and a
     * lexicon this server has never seen is exactly the case the network is
     * built for.
     *
     * @param  array<mixed>  $value
     * @return array<int, string>
     */
    private function unresolved(array $value, string $did): array
    {
        $missing = [];

        if (($value['$type'] ?? null) === 'blob') {
            $link = $value['ref']['$link'] ?? null;

            if (is_string($link) && ! $this->blobs->holds($did, $link)) {
                $missing[] = $link;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $missing = [...$missing, ...$this->unresolved($nested, $did)];
            }
        }

        return array_values(array_unique($missing));
    }
}
