<?php

namespace StreetMesh\Server\Protocol\Attestations;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use StreetMesh\Protocol\Jws;
use StreetMesh\Protocol\SigningKey;
use StreetMesh\Server\Protocol\Identity\DidResolver;
use Throwable;

/**
 * Making statements other servers can check, and checking theirs.
 *
 * A venue signs what happened; a domicile checks it before keeping it. Neither
 * side has to trust the other, and neither has to still exist for the statement
 * to remain checkable — which is the property that makes a record worth holding
 * rather than merely worth receiving.
 */
final class Attestations
{
    public function __construct(private readonly DidResolver $resolver) {}

    /**
     * Sign a statement as this server.
     *
     * Takes a `SigningKey` rather than a particular curve, and that is not
     * tidying: it took an `Ed25519`, while every identity this package mints is
     * P-256 because `did:plc` will not accept Ed25519 at all. So this method
     * could not be called with the key of the server it belonged to, which is
     * why nothing ever called it.
     *
     * @param  array<string, mixed>  $claims
     * @param  string  $keyId  a verification method in this server's DID document
     */
    public function issue(array $claims, SigningKey $key, string $keyId): string
    {
        return Jws::sign($claims, $key, $keyId);
    }

    /**
     * Check somebody else's statement, as of a moment.
     *
     * `$receivedAt` should be when this server saw the document, not when the
     * document says it was issued. The difference is that the first is asserted
     * by the party doing the checking and the second by the party being checked,
     * and only one of those can be backdated by somebody holding a retired key.
     */
    public function verify(string $compact, ?DateTimeInterface $receivedAt = null): Attestation
    {
        $receivedAt = $receivedAt === null
            ? new DateTimeImmutable
            : DateTimeImmutable::createFromInterface($receivedAt);

        /*
         * Read which key the document names before trusting any of it. Reading
         * an unverified header is not trusting it: the `kid` says where to look,
         * and the check that follows is what decides whether the answer counts.
         */
        $keyId = Jws::keyId($compact);

        $key = $this->resolver->keyAt($keyId, $receivedAt);

        try {
            $claims = Jws::verify($compact, $key->multikey);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "That statement does not verify against the key [{$keyId}] "
                .($key->historical
                    ? 'was using when it arrived.'
                    : 'publishes now — and its issuer keeps no key history, so an earlier key cannot be checked.'),
                previous: $e,
            );
        }

        return new Attestation(
            compact: $compact,
            issuer: explode('#', $keyId)[0],
            keyId: $keyId,
            claims: $claims,
            verifiedAt: $receivedAt,
            key: $key,
        );
    }
}
