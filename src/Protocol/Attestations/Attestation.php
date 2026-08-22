<?php

namespace StreetMesh\Server\Protocol\Attestations;

use DateTimeImmutable;
use DateTimeInterface;
use StreetMesh\Server\Protocol\Identity\VerificationKey;

/**
 * Somebody else's signed statement about what happened, once it has been checked.
 *
 * The point of the whole exercise. A venue ran the game and says who won; the
 * player holds that statement on their own server. It is worth holding because
 * it can be checked by anybody, later, with nothing but the document and public
 * infrastructure — not because the venue is still around to be asked, and not
 * because the server holding it is trusted.
 *
 * This object only exists for statements that have already verified. There is no
 * way to construct an unverified one, so anything with an Attestation in hand is
 * holding something that checked out rather than something that arrived.
 */
final class Attestation
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly string $compact,
        public readonly string $issuer,
        public readonly string $keyId,
        public readonly array $claims,
        public readonly DateTimeImmutable $verifiedAt,
        public readonly VerificationKey $key,
    ) {}

    /**
     * Was this checked against a key demonstrably current when it was signed?
     *
     * False means the issuer publishes no key history, so the check used
     * whatever key is current now. Fine for something signed this morning, and
     * an assumption rather than a verification for anything older.
     */
    public function checkedAgainstHistory(): bool
    {
        return $this->key->historical;
    }

    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    /**
     * The whole thing, as it should be stored.
     *
     * The compact form is kept because it is the only part that can be checked
     * again later; the decoded claims are kept beside it so that reading a
     * record does not require verifying it first. Where they disagree, the
     * compact form is the truth and the copy is a stale convenience.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            ...$this->claims,
            'attestation' => $this->compact,
            'issuer' => $this->issuer,
            'receivedAt' => $this->verifiedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
