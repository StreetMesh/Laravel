<?php

namespace StreetMesh\Server\Protocol\Identity;

use DateTimeInterface;
use JsonException;
use RuntimeException;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Discovery\IdentityDoesNotResolve;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;

/**
 * Turning an identifier into a key you can check a signature with.
 *
 * Two methods are supported, and they are not equivalent. `did:plc` derives its
 * identifier from a signed genesis operation and keeps every later operation in
 * a public log, so it can answer both "which key now" and "which key then".
 * `did:web` derives its identifier from a location and publishes a document, so
 * it can only answer the first — and an identifier that is a location also stops
 * being true when its subject moves.
 *
 * Both are here because both exist on the network. Which one an identity uses is
 * its own business; what a verifier must not do is treat the two answers as the
 * same kind of answer, which is what VerificationKey is for.
 */
final class DidResolver
{
    public function __construct(
        private readonly Network $network,
        private readonly PlcDirectory $directory,
    ) {}

    /**
     * The key named by a `kid`, as it stood at a given moment.
     *
     * The moment is the caller's to choose and the choice has consequences: a
     * timestamp inside the document being verified is asserted by whoever signed
     * it and can be backdated, while the moment the document was received is
     * asserted by the party doing the verifying and cannot.
     */
    public function keyAt(string $keyId, DateTimeInterface $at): VerificationKey
    {
        [$did, $fragment] = $this->split($keyId);

        if (str_starts_with($did, 'did:plc:')) {
            return VerificationKey::asOf(
                Plc::keyAt($this->directory->auditLog($did), $at, $fragment),
            );
        }

        if (str_starts_with($did, 'did:web:')) {
            return VerificationKey::current($this->fromWebDocument($did, $keyId));
        }

        throw new RuntimeException("[{$did}] uses a DID method this server does not resolve.");
    }

    /**
     * @return array<string, mixed>
     */
    public function document(string $did): array
    {
        if (str_starts_with($did, 'did:plc:')) {
            return $this->directory->resolve($did);
        }

        if (str_starts_with($did, 'did:web:')) {
            return $this->fetchDocument(Did::parse($did)->documentUrl(), $did);
        }

        throw new RuntimeException("[{$did}] uses a DID method this server does not resolve.");
    }

    private function fromWebDocument(string $did, string $keyId): string
    {
        $document = $this->document($did);

        foreach ($document['verificationMethod'] ?? [] as $method) {
            /*
             * Matched on the whole identifier rather than on position, because
             * a document may publish several keys for different purposes and
             * taking the first would be verifying against whichever one happens
             * to be listed earliest.
             */
            if (($method['id'] ?? null) === $keyId && isset($method['publicKeyMultibase'])) {
                return $method['publicKeyMultibase'];
            }
        }

        throw new RuntimeException("[{$keyId}] is not a verification method in that document.");
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $url, string $did): array
    {
        $body = $this->network->get($url) ?? throw new IdentityDoesNotResolve("[{$did}] did not resolve.");

        try {
            $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("[{$did}] did not answer with a document.", previous: $e);
        }

        if (! is_array($document) || ($document['id'] ?? null) !== $did) {
            /*
             * A document that names somebody else is not this identity's
             * document, however it was reached. Without this, anything able to
             * influence where a lookup lands could substitute an identity.
             */
            throw new RuntimeException("The document at [{$url}] does not claim to be [{$did}].");
        }

        return $document;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $keyId): array
    {
        $parts = explode('#', $keyId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException(
                "[{$keyId}] names no particular key. A signature has to say which key checks it, "
                .'not merely whose it is.'
            );
        }

        return [$parts[0], $parts[1]];
    }
}
