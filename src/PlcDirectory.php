<?php

namespace StreetMesh\Protocol;

use DateTimeInterface;
use JsonException;
use RuntimeException;

/**
 * Asking the directory what it holds for an identity.
 *
 * Kept apart from `Plc` so that deriving a DID stays a pure function of its
 * operation — bytes in, identifier out, no network, no clock, testable against
 * the conformance vectors alone. This is the half that needs the world.
 *
 * What the directory is trusted for is worth being exact about, because it
 * decides whether depending on it is acceptable at all: a DID is derived from a
 * signed genesis operation, and every later operation must be signed by a key
 * its subject holds. The directory therefore cannot forge an identity, invent
 * one, or reassign one. It can only decline to answer. Availability, not
 * authenticity — and the audit log below is what lets anybody check that.
 */
final class PlcDirectory
{
    public const DEFAULT = 'https://plc.directory';

    public function __construct(
        private readonly Network $network = new Curl,
        private readonly string $directory = self::DEFAULT,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $did): array
    {
        return $this->json($this->directory.'/'.$did, "[{$did}] did not resolve.");
    }

    /**
     * The signed operations behind a DID, oldest first.
     *
     * Auditable by anyone, which is what keeps the directory honest — a
     * substituted key is visible in the log rather than silent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditLog(string $did): array
    {
        return $this->json(
            $this->directory.'/'.$did.'/log/audit',
            "[{$did}] has no readable log.",
        );
    }

    /**
     * The operation an identity was born from.
     *
     * @return array<string, mixed>
     */
    public function genesisOf(string $did): array
    {
        $log = $this->auditLog($did);

        return $log[0]['operation']
            ?? throw new RuntimeException("[{$did}] has an empty log, which should not be possible.");
    }

    /**
     * The key an identity was using at a given moment, fetched and resolved.
     *
     * The convenience over Plc::keyAt for callers that have a DID rather than
     * a log — the derivation itself stays pure and offline over there.
     */
    public function keyAt(string $did, DateTimeInterface $at, string $fragment = 'atproto'): string
    {
        return Plc::keyAt($this->auditLog($did), $at, $fragment);
    }

    /**
     * Put a signed operation on the record.
     *
     * The only method here that writes, and the only one whose failure is worth
     * being loud about. Everything else asks a question with a useful negative
     * answer; this either establishes an identity or does not, and a caller
     * that carried on believing it had would hold a DID nobody can resolve.
     *
     * What the directory checks is what keeps it honest: the signature, and
     * that `prev` names the operation actually at the head of this DID's log.
     * It cannot invent an identity or reassign one — but it can and will refuse
     * a badly formed one, and that refusal is worth reading rather than
     * flattening into a boolean.
     *
     * @param  array<string, mixed>  $operation  signed, from Plc::genesis or Plc::update
     */
    public function submit(string $did, array $operation): void
    {
        $answer = $this->network->post(
            $this->directory.'/'.$did,
            json_encode($operation, JSON_THROW_ON_ERROR),
        );

        if ($answer['status'] >= 200 && $answer['status'] < 300) {
            return;
        }

        throw new RuntimeException(
            "The directory refused the operation for [{$did}]: "
            .($answer['body'] === '' ? "it answered {$answer['status']} and nothing else" : $answer['body'])
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(string $url, string $complaint): array
    {
        $body = $this->network->get($url) ?? throw new RuntimeException($complaint);

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException($complaint.' It did not answer with JSON.', previous: $e);
        }

        return is_array($decoded) ? $decoded : throw new RuntimeException($complaint);
    }
}
