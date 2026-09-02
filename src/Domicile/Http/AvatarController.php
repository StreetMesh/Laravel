<?php

namespace StreetMesh\Server\Domicile\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Domicile\Avatars\Letter;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Identity\Identities;

/**
 * What somebody looks like, at their own address.
 *
 * `collegeman.stme.sh/avatar/icon` is a resident's face, served by the server
 * that answers for their name and by nothing else. That is the whole of the
 * design: not a signature, not a document asserting where a picture lives, but
 * an address only one party can answer for. A venue drawing this has fetched it
 * from the person's own server, which is a stronger thing to be able to say
 * than that somebody signed a copy.
 *
 * Which resident is decided by the hostname, exactly as it is for the identity
 * documents alongside — a resident's handle is a name under this server's own,
 * so the same paths serve many people and the host is what tells them apart.
 *
 * Deliberately unauthenticated, and outside the `web` group. Both matter. A
 * venue asking has no session here and never will; and `SendResidentsHome`
 * redirects every browser route on a resident hostname to their profile, so a
 * route registered in `web` would be answered with a permanent redirect and
 * never run at all.
 */
class AvatarController
{
    public function __construct(
        private readonly Identities $identities,
        private readonly Avatars $avatars,
        private readonly BlobStore $blobs,
    ) {}

    /**
     * The 2D one: what a party draws before anybody turns a camera on.
     */
    public function icon(Request $request): Response
    {
        $resident = $this->identities->byHandle($request->getHost());

        /*
         * The server's own name is not somebody. A domicile has an identity and
         * it is not a person, so asking this server's own host about its face
         * should find nothing rather than answer for a machine.
         */
        if ($resident === null || $resident->is_server) {
            return $this->nobody();
        }

        $avatar = $this->avatars->defaultFor((string) $resident->did);
        $blob = $avatar?->icon();
        $bytes = $blob === null ? null : $this->blobs->bytes($blob);

        /*
         * Somebody who lives here always has a face, even before they have
         * chosen one. A refusal would be the honest answer to "is there a
         * picture", but that is not the question being asked — the question is
         * what this person looks like, and their letter is a real answer to it.
         *
         * Which is why this is not reached for a name nobody goes by. There is
         * no letter to draw for somebody who does not live here, and drawing
         * one would be this server inventing a resident.
         *
         * The same branch covers a blob the disk has lost. Nothing about that
         * is the reader's problem, and a letter is what they would have been
         * shown anyway.
         */
        if ($bytes === null) {
            return $this->letterFor((string) $resident->handle, $request);
        }

        /* The tag is the picture's own name. See `served` for the clock. */
        $answer = $this->served($bytes, $blob->mime);

        $answer->setEtag($blob->cid);
        $answer->isNotModified($request);

        return $answer;
    }

    /**
     * The 3D one: the body a spatial place puts somebody in.
     *
     * The same shape as `icon` and deliberately not the same behaviour in one
     * place — there is no letter to fall back to. A drawn initial is a real
     * answer to "what does this person look like"; there is no equivalent
     * answer to "what body should I put them in", and inventing one would have
     * every spatial place quietly agreeing on a default nobody chose. So a
     * resident who has not built one is answered with nothing, and the caller
     * decides what to do about it.
     */
    public function model(Request $request): Response
    {
        $resident = $this->identities->byHandle($request->getHost());

        if ($resident === null || $resident->is_server) {
            return $this->nobody();
        }

        $avatar = $this->avatars->defaultFor((string) $resident->did);
        $blob = $avatar?->model();
        $bytes = $blob === null ? null : $this->blobs->bytes($blob);

        if ($bytes === null) {
            return $this->nobody();
        }

        $answer = $this->served($bytes, $blob->mime);

        $answer->setEtag($blob->cid);
        $answer->isNotModified($request);

        return $answer;
    }

    /**
     * Their initial, drawn here, for a resident who has published nothing.
     *
     * Answered as an ordinary picture rather than as a placeholder, because to
     * whoever asked there is no difference: they wanted to know what this
     * person looks like and now they do. A caller that has to branch on the
     * status code to find out whether it got an image is a caller doing work
     * this server could have done once.
     *
     * The entity tag is the drawing's rather than the content's, and the two
     * cannot collide — one begins `letter-` and the other is a CID. So a
     * browser holding a letter is handed the real picture the moment there is
     * one, without waiting for anything to expire.
     */
    private function letterFor(string $handle, Request $request): Response
    {
        $letter = Letter::for($handle);

        $answer = $this->served($letter->bytes, 'image/svg+xml');

        $answer->setEtag($letter->etag);
        $answer->isNotModified($request);

        return $answer;
    }

    /**
     * Nothing, and nothing about why.
     *
     * One answer for "nobody goes by that name here", "that is this server's
     * own name, which is not a person", and — for the model alone — "that
     * person has not built one". A resident who exists is always answered for
     * with a picture, so a 404 from `icon` means the name is not one this
     * server knows; a 404 from `model` most often means there is no body yet.
     */
    private function nobody(): Response
    {
        return response('', 404);
    }

    /**
     * The headers everything here carries, whichever kind it is.
     *
     * A picture, a letter, a body: one set of headers, because a second subtly
     * different answer to "may a browser run this" is how the two drift.
     *
     * They matter more than they look, and most of all for the letter: SVG is a
     * document format, and these bytes come back from the origin that also
     * answers for somebody's identity. A browser must not be talked into
     * rendering one as a page.
     */
    private function served(string $bytes, string $type): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,

            /*
             * Readable from anywhere, because being readable from anywhere is
             * the entire point of publishing it here.
             *
             * An `<img>` never needed this, which is why nothing did until
             * now: a browser will draw a cross-origin picture without asking
             * permission. A model is not drawn by the browser — it is fetched
             * by whatever is going to render it, and a fetch from another
             * origin is refused without this header. Without it the body at a
             * resident's own address would be readable only by the server that
             * already has it.
             *
             * Safe to say for both, and said for both rather than only for the
             * model, because two answers to "who may read this" is how the two
             * drift. Nothing here is secret — the visibility question was
             * settled when the bytes were stored, and private bytes are not
             * reached by this controller at all.
             */
            'Access-Control-Allow-Origin' => '*',

            /*
             * Asked for every time, and almost never sent.
             *
             * This is a stable path over changing content, which is the one
             * shape a lifetime cannot describe: any `max-age` at all is a
             * window in which somebody has changed their face and nobody can
             * see it. It was five minutes, and what that looked like was
             * publishing a picture at home, walking back to the venue, and
             * still being a letter — with nothing to do about it but wait.
             *
             * So every visit revalidates. It costs a conditional request per
             * face per page, and the reply to almost all of them is 304 with no
             * body, because the tag is the content's own name. The bytes cross
             * the wire when they have actually changed and not otherwise.
             *
             * Content-addressed URLs are the opposite case and are cached hard
             * — see `com.atproto.sync.getBlob`, where the name is in the
             * address and the answer can never become wrong.
             */
            'Cache-Control' => 'public, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
