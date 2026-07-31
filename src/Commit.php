<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * A person's own signature over their own records.
 *
 * Without this, a record proves only that it has not changed since it was named.
 * Nothing says the person it belongs to ever agreed to it, and nothing says the
 * set is complete — a server could add a record its resident never made, or quietly
 * drop one, and no reader could tell.
 *
 * What a commit does not do is worth being exact about, because the obvious
 * reading is wrong. The signing key is normally held by the server somebody
 * lives on, so a dishonest server can sign as them regardless. **A commit does
 * not stop a server lying about its residents.** What it does is make the lie
 * permanent, attributable and detectable: every commit names the one before it,
 * so history is a chain rather than a state, and anybody who saw an earlier link
 * can prove a later one contradicts it. Rewriting the past stops being invisible
 * and becomes a fork somebody can point at.
 *
 * That is a weaker guarantee than it first sounds and a much stronger one than
 * nothing, and it is the same guarantee ATProtocol offers for the same reason.
 *
 * The fields are theirs, so that a chain built here is one their software could
 * read. See `data` for the one part that is not yet compatible.
 */
final class Commit
{
    public const VERSION = 3;

    /**
     * @param  string  $did  whose records these are
     * @param  string  $data  root of the record tree
     * @param  string|null  $prev  the commit before this one, or null for the first
     * @param  string  $rev  when, as a record key — so commits sort like everything else
     */
    private function __construct(
        public readonly string $did,
        public readonly string $data,
        public readonly ?string $prev,
        public readonly string $rev,
        public readonly ?string $signature = null,
    ) {}

    public static function of(string $did, string $data, ?string $prev = null, ?Tid $rev = null): self
    {
        return new self($did, $data, $prev, (string) ($rev ?? Tid::now()));
    }

    /**
     * Sign it, which is the point.
     */
    public function signedWith(Ed25519 $key): self
    {
        $signature = $key->sign(DagCbor::encode($this->unsigned()));

        return new self(
            $this->did,
            $this->data,
            $this->prev,
            $this->rev,
            rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        );
    }

    /**
     * Does this commit's signature check out against a key?
     */
    public function verify(string $publicKey): bool
    {
        if ($this->signature === null) {
            return false;
        }

        return Ed25519::verify(
            DagCbor::encode($this->unsigned()),
            $this->signature,
            $publicKey,
        );
    }

    /**
     * The commit's own name, which the next one will point at.
     */
    public function cid(): Cid
    {
        return Cid::forRecord($this->toArray());
    }

    /**
     * Is this commit the one that follows another?
     *
     * The check that makes history a chain. A server that rewrites the past
     * produces a link that does not fit, and anybody holding the earlier one can
     * say so.
     */
    public function follows(self $earlier): bool
    {
        return $this->prev === (string) $earlier->cid()
            && $this->did === $earlier->did
            && $this->rev > $earlier->rev;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $commit = $this->unsigned();

        if ($this->signature !== null) {
            $commit['sig'] = $this->signature;
        }

        return $commit;
    }

    /**
     * @param  array<string, mixed>  $commit
     */
    public static function fromArray(array $commit): self
    {
        foreach (['did', 'data', 'rev'] as $required) {
            if (! isset($commit[$required]) || ! is_string($commit[$required])) {
                throw new RuntimeException("A commit without [{$required}] is not a commit.");
            }
        }

        return new self(
            $commit['did'],
            $commit['data'],
            $commit['prev'] ?? null,
            $commit['rev'],
            $commit['sig'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function unsigned(): array
    {
        return [
            'did' => $this->did,
            'version' => self::VERSION,
            'data' => $this->data,
            'prev' => $this->prev,
            'rev' => $this->rev,
        ];
    }
}
