<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Identity\DidDocument;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;

/**
 * Answering "who are you?" to anybody who asks.
 *
 * Unauthenticated on purpose, and it has to be. A record is meant to be
 * checkable years later by somebody with no relationship to this server — if
 * finding out which key signed it required an account, the record would only be
 * as durable as the arrangement between two parties, which is the thing being
 * replaced.
 */
class IdentityController
{
    public function __construct(
        private readonly Identities $identities,
        private readonly Capabilities $capabilities,
    ) {}

    /**
     * Whose document this hostname asks for.
     *
     * A resident's handle is a name under this server's own — alice.games.test
     * — so the same two documents are served for several identities and the
     * hostname is what distinguishes them. Anything unrecognized is the server
     * itself, which is what a request arriving as `localhost` or by IP means.
     */
    private function subject(Request $request): Identity
    {
        return $this->identities->byHandle($request->getHost())
            ?? $this->identities->forServer();
    }

    /**
     * Where the repositories this server holds are reached.
     */
    private function origin(): string
    {
        $server = $this->identities->forServer();

        /*
         * `??` rather than config()'s default, which applies only when a key is
         * absent — and both of these are present and null whenever their
         * environment variables are unset, which is the ordinary case. So the
         * default was never reached, and this published `https://` with no host
         * after it: every venue walking the chain to this server found nothing
         * at the end of it.
         *
         * The identity's own handle is the last resort, because a server that
         * knows what it is called can say so even when nobody has configured it.
         */
        return (string) (config('streetmesh.origin')
            ?? 'https://'.(config('streetmesh.host') ?? $server->handle));
    }

    /**
     * A DID document — this server's, or that of somebody who lives here.
     */
    public function document(Request $request): JsonResponse
    {
        $identity = $this->subject($request);

        /*
         * A resident is not a venue and does not run anything. Their document
         * says only who they are and where their repository is kept, and where
         * it is kept is here — so the endpoint is this server's, not a URL
         * built from their own name. Their name resolves to this building; it
         * is not a building of its own.
         */
        if (! $identity->is_server) {
            return response()->json(DidDocument::for(
                $identity,
                $this->origin(),
                'AtprotoPersonalDataServer',
            ));
        }

        /*
         * What this server does, taken from what is actually installed rather
         * than from a separate list — so the document and the application cannot
         * drift into disagreeing about it.
         */
        $types = array_map(
            fn ($capability): string => $capability->serviceType(),
            $this->capabilities->all(),
        );

        return response()->json(DidDocument::for(
            $identity,
            $this->origin(),
            $types === [] ? 'AtprotoPersonalDataServer' : $types,
        ));
    }

    /**
     * Which identity this hostname stands for.
     *
     * Plain text, as ATProtocol expects. The other half of handle resolution:
     * a document claims a name, and this is the name pointing back. Answered
     * per hostname, because everybody who lives here has a name under this
     * server's — and a resident whose name resolved to the server's DID would
     * be handing every venue the wrong identity.
     */
    public function handle(Request $request): Response
    {
        return response($this->subject($request)->did)
            ->header('Content-Type', 'text/plain');
    }
}
