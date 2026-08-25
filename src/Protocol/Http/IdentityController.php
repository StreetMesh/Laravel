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
     * Whose document this hostname asks for, or nobody's.
     *
     * A resident's handle is a name under this server's own — alice.games.test
     * — so the same two documents are served for several identities and the
     * hostname is what distinguishes them. A request arriving as `localhost` or
     * by IP, or on some name pointed here by somebody else, is this server being
     * asked about itself.
     *
     * A name *under* this server's that nobody holds is neither of those, and
     * used to be answered as though it were the second. That made an address
     * nobody has taken indistinguishable from a domicile claiming an identity
     * which disowns the name: resolution succeeded, the bidirectional check
     * failed, and both arrived as the same error. The first is an opportunity
     * and the second is a server lying, and no venue could tell them apart.
     *
     * So it is nobody, and this says so.
     */
    private function subject(Request $request): ?Identity
    {
        $host = strtolower($request->getHost());

        $identity = $this->identities->byHandle($host);

        if ($identity !== null) {
            return $identity;
        }

        return $this->named($host) ? null : $this->identities->forServer();
    }

    /**
     * Whether a hostname is the shape a resident's address takes here.
     *
     * Only that — not whether anybody holds it, which is the question the caller
     * has already asked. `alice.games.test` under `games.test` is a name this
     * server is responsible for having or not having. `games.test` itself is the
     * building, and anything else is a stranger.
     */
    private function named(string $host): bool
    {
        $server = strtolower((string) (config('streetmesh.host') ?? $this->identities->forServer()->handle));

        return $server !== '' && str_ends_with($host, '.'.$server);
    }

    /**
     * Nobody goes by that name here.
     *
     * The same answer `/avatar/icon` has always given for an address nobody
     * holds. The two endpoints answer one question between them and disagreeing
     * about it was the bug.
     */
    private function nobody(): Response
    {
        return response('', 404);
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
    public function document(Request $request): JsonResponse|Response
    {
        $identity = $this->subject($request);

        if ($identity === null) {
            return $this->nobody();
        }

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
        $identity = $this->subject($request);

        if ($identity === null) {
            return $this->nobody();
        }

        return response($identity->did)
            ->header('Content-Type', 'text/plain');
    }
}
