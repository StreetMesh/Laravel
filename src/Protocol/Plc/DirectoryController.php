<?php

namespace StreetMesh\Server\Protocol\Plc;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The four endpoints a PLC directory answers.
 *
 * Shaped after the public directory's, path for path, because the client
 * reading this is the same client that reads that one — a local directory
 * needing special handling in the client would be a local directory that proves
 * nothing about production.
 *
 * Outside the browser middleware entirely. The caller is another server, or
 * this one talking to itself, and neither has a session to start or a CSRF
 * token to present.
 */
final class DirectoryController
{
    public function __construct(private readonly Directory $directory) {}

    /**
     * Whether this server is a directory at all.
     *
     * Asked per request rather than when routes are registered. A setting read
     * at boot gets baked into a cached route table, and the symptom is an
     * operator turning this on and nothing whatsoever happening until somebody
     * thinks to clear a cache.
     */
    private function refuseUnlessHosting(): void
    {
        if (! $this->directory->hosting()) {
            throw new NotFoundHttpException('This server does not keep a PLC directory.');
        }
    }

    public function health(): JsonResponse
    {
        $this->refuseUnlessHosting();

        return response()->json(['version' => 'streetmesh-plc']);
    }

    public function resolve(string $did): JsonResponse
    {
        $this->refuseUnlessHosting();

        $document = $this->directory->documentFor($did);

        if ($document === null) {
            return response()->json(['message' => 'DID not registered: '.$did], 404);
        }

        return response()->json($document);
    }

    public function log(string $did): JsonResponse
    {
        $this->refuseUnlessHosting();

        $log = $this->directory->auditLog($did);

        if ($log === []) {
            return response()->json(['message' => 'DID not registered: '.$did], 404);
        }

        return response()->json($log);
    }

    /**
     * Take a signed operation.
     *
     * A refusal comes back as its own sentence rather than as a status alone.
     * Everything that goes wrong here — a stale `prev`, a derived identifier
     * that does not match, a signature from a key with no authority — is
     * something whoever sent it can act on, and 400 by itself sends them to a
     * packet capture to find out which.
     */
    public function submit(Request $request, string $did): JsonResponse
    {
        $this->refuseUnlessHosting();

        /** @var array<string, mixed> $operation */
        $operation = $request->json()->all();

        if ($operation === []) {
            return response()->json(['message' => 'That request carried no operation.'], 400);
        }

        try {
            $this->directory->submit($did, $operation);
        } catch (Throwable $refused) {
            return response()->json(['message' => $refused->getMessage()], 400);
        }

        return response()->json([], 200);
    }
}
