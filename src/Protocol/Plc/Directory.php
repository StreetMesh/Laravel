<?php

namespace StreetMesh\Server\Protocol\Plc;

use RuntimeException;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\DagCbor;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\Signature;

/**
 * A PLC directory, kept by this server.
 *
 * The public one at plc.directory is a small Node service and a database, and
 * for development that is a container, a compose file and a daemon somebody has
 * to remember to start — a Docker dependency bolted to a Laravel application
 * for the sake of four endpoints. This is those four endpoints.
 *
 * It is deliberately not a reimplementation of anybody's product. What a
 * directory is trusted for is narrow and is the whole reason depending on one
 * is acceptable at all: a DID is derived from a signed genesis operation, and
 * every operation after it must be signed by a key the subject holds and must
 * name the operation it was written against. So a directory cannot forge an
 * identity, invent one, or reassign one. It can only decline to answer.
 *
 * That is the property being preserved here, and it is why this can be short.
 * Everything a directory actually does is: check a signature, check a chain,
 * and remember. The DID document is derived on every read rather than stored,
 * because a stored one is a second copy of something the log already says.
 *
 * **Use the real directory in production.** This exists so that a developer
 * needs Postgres-free, Docker-free, one-command local identities — not so that
 * anybody runs their own registry in earnest. Entries here are as permanent as
 * this server's database and resolvable by nobody else.
 */
final class Directory
{
    /**
     * The one thing here that is not implemented, said out loud.
     *
     * The real directory lets a higher-priority rotation key fork the chain
     * within 72 hours, nullifying what a lower-priority key did — which is how
     * somebody recovers an identity from a server that has gone bad. Nothing
     * here does that: a conflicting operation is refused rather than allowed to
     * fork.
     *
     * That is the right trade for a development directory and the wrong one for
     * a real registry, and it is written here rather than discovered because a
     * missing recovery path is invisible until the day it is the only thing
     * that matters.
     */
    public const RECOVERS = false;

    public function hosting(): bool
    {
        return (bool) config('streetmesh.plc.host', false);
    }

    /**
     * The newest operation in an identity's chain.
     *
     * What the next operation has to name, and what the DID document is built
     * from. Nullified entries are skipped: they stay in the log so a recovery
     * is auditable and must never be read as the current state.
     */
    public function head(string $did): ?Operation
    {
        return Operation::query()
            ->where('did', $did)
            ->where('nullified', false)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Take a signed operation, or say exactly why not.
     *
     * Every refusal here is a sentence somebody debugging their own client
     * needs to read. A directory that answered 400 and nothing else would send
     * them to a packet capture to find out that a `prev` was stale.
     *
     * @param  array<string, mixed>  $operation
     */
    public function submit(string $did, array $operation): Operation
    {
        if (! isset($operation['sig']) || ! is_string($operation['sig'])) {
            throw new RuntimeException('That operation carries no signature.');
        }

        $head = $this->head($did);
        $prev = $operation['prev'] ?? null;

        $rotationKeys = $prev === null
            ? $this->genesisKeys($did, $operation, $head)
            : $this->inheritedKeys($did, $operation, $head, (string) $prev);

        if (! $this->signedByOneOf($operation, $rotationKeys)) {
            throw new RuntimeException(
                'That operation is not signed by any key entitled to make it. '
                .'A genesis operation is signed by one of its own rotation keys; every later one '
                .'by a rotation key named in the operation it follows.'
            );
        }

        return Operation::create([
            'did' => $did,
            'cid' => (string) Cid::forRecord($operation),
            'prev' => $prev,
            'operation' => $operation,
            'nullified' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * The keys entitled to sign the operation that creates an identity.
     *
     * Its own, which sounds circular and is not: the DID is the hash of this
     * operation including those keys, so an operation naming different keys is
     * a different identity with a different identifier. That is what makes the
     * identifier unforgeable rather than merely unique.
     *
     * @param  array<string, mixed>  $operation
     * @return array<int, string>
     */
    private function genesisKeys(string $did, array $operation, ?Operation $head): array
    {
        if ($head !== null) {
            throw new RuntimeException(
                "[{$did}] already exists here. An operation with no `prev` creates an identity, "
                .'and this one has a chain already — send one naming the head of it instead.'
            );
        }

        $derived = Plc::did($operation);

        if ($derived !== $did) {
            throw new RuntimeException(
                "That operation creates [{$derived}], not [{$did}]. A did:plc is the hash of the "
                .'operation that made it, so the two cannot be chosen independently.'
            );
        }

        return $this->rotationKeysOf($operation);
    }

    /**
     * The keys entitled to sign an operation that changes an existing identity.
     *
     * Named by the operation *being followed* rather than by this one, which is
     * what stops a stolen signing key rewriting the rotation list and taking
     * the identity with it.
     *
     * @param  array<string, mixed>  $operation
     * @return array<int, string>
     */
    private function inheritedKeys(string $did, array $operation, ?Operation $head, string $prev): array
    {
        if ($head === null) {
            throw new RuntimeException(
                "[{$did}] is not here, so there is nothing for that operation to follow. "
                .'An identity is created by an operation whose `prev` is null.'
            );
        }

        if ($prev !== $head->cid) {
            throw new RuntimeException(
                "That operation follows [{$prev}], but the head of [{$did}] is [{$head->cid}]. "
                .'Fetch the log again and write against what is actually there.'
            );
        }

        return $this->rotationKeysOf($head->operation);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<int, string>
     */
    private function rotationKeysOf(array $operation): array
    {
        $keys = $operation['rotationKeys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * Whether one of these keys signed this operation.
     *
     * Any of them, rather than the highest-priority one. Priority decides who
     * wins a conflict during recovery, which this does not implement — see
     * `RECOVERS`.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<int, string>  $rotationKeys  as `did:key:` strings
     */
    private function signedByOneOf(array $operation, array $rotationKeys): bool
    {
        $signature = $this->decodeSignature((string) $operation['sig']);

        if ($signature === null) {
            return false;
        }

        /*
         * Signed over the operation without its signature, which is the only
         * form of it that ever existed at signing time.
         */
        unset($operation['sig']);

        $message = DagCbor::encode($operation);

        foreach ($rotationKeys as $key) {
            $multikey = str_starts_with($key, 'did:key:') ? substr($key, strlen('did:key:')) : $key;

            if (Signature::verify($multikey, $message, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * base64url, padded back to something PHP will decode.
     *
     * Null rather than a throw: anything at all can arrive at this door, and a
     * signature that is not base64 is an ordinary refusal rather than something
     * to raise a parser error about.
     */
    private function decodeSignature(string $signature): ?string
    {
        $padded = strtr($signature, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, strict: true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The DID document for an identity, derived from the head of its chain.
     *
     * Built on every read rather than stored. A stored document would be a
     * second copy of what the log already says, and the two would eventually
     * disagree about somebody's key — which is the one thing a directory must
     * never be uncertain about.
     *
     * @return array<string, mixed>|null
     */
    public function documentFor(string $did): ?array
    {
        $head = $this->head($did);

        if ($head === null) {
            return null;
        }

        $operation = $head->operation;
        $document = [
            '@context' => [
                'https://www.w3.org/ns/did/v1',
                'https://w3id.org/security/multikey/v1',
            ],
            'id' => $did,
        ];

        if (($operation['alsoKnownAs'] ?? []) !== []) {
            $document['alsoKnownAs'] = array_values((array) $operation['alsoKnownAs']);
        }

        $document['verificationMethod'] = [];

        foreach ((array) ($operation['verificationMethods'] ?? []) as $fragment => $key) {
            $document['verificationMethod'][] = [
                'id' => $did.'#'.$fragment,
                'type' => 'Multikey',
                'controller' => $did,
                'publicKeyMultibase' => str_starts_with((string) $key, 'did:key:')
                    ? substr((string) $key, strlen('did:key:'))
                    : (string) $key,
            ];
        }

        $document['service'] = [];

        foreach ((array) ($operation['services'] ?? []) as $name => $service) {
            $document['service'][] = [
                'id' => '#'.$name,
                'type' => $service['type'] ?? '',
                'serviceEndpoint' => $service['endpoint'] ?? '',
            ];
        }

        return $document;
    }

    /**
     * Every operation behind a DID, oldest first, nullified ones included.
     *
     * Auditable by anybody, which is what keeps a directory honest — a
     * substituted key is visible in the log rather than silent. Nullified
     * entries are part of that: leaving them out would hide exactly the event
     * somebody auditing is looking for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditLog(string $did): array
    {
        return Operation::query()
            ->where('did', $did)
            ->orderBy('id')
            ->get()
            ->map(fn (Operation $entry): array => $entry->asLogEntry())
            ->all();
    }
}
