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
     * @param  Cid  $data  root of the record tree — a link, not text
     * @param  Cid|null  $prev  the commit before this one, or null for the first
     * @param  string  $rev  when, as a record key — so commits sort like everything else
     * @param  Bytes|null  $signature  64 raw bytes, not text
     */
    private function __construct(
        public readonly string $did,
        public readonly Cid $data,
        public readonly ?Cid $prev,
        public readonly string $rev,
        public readonly ?Bytes $signature = null,
    ) {}

    public static function of(string $did, Cid|string $data, Cid|string|null $prev = null, ?Tid $rev = null): self
    {
        return new self(
            $did,
            $data instanceof Cid ? $data : Cid::parse($data),
            $prev === null ? null : ($prev instanceof Cid ? $prev : Cid::parse($prev)),
            (string) ($rev ?? Tid::now()),
        );
    }

    /**
     * Sign it, which is the point.
     */
    public function signedWith(SigningKey $key): self
    {
        return new self(
            $this->did,
            $this->data,
            $this->prev,
            $this->rev,
            new Bytes($key->sign(DagCbor::encode($this->unsigned()))),
        );
    }

    /**
     * Does this commit's signature check out against a published key?
     *
     * Takes the key as its owner published it — curve included — because the
     * verifier does not get to choose the curve and an implementation that
     * assumes one can read only its own documents.
     */
    public function verify(string $multikey): bool
    {
        if ($this->signature === null) {
            return false;
        }

        return Signature::verify(
            $multikey,
            DagCbor::encode($this->unsigned()),
            $this->signature->value,
        );
    }

    /**
     * The commit's own name, which the next one will point at.
     */
    public function cid(): Cid
    {
        return Cid::forBytes($this->toBytes());
    }

    /**
     * The commit as it travels — which is the only form worth naming, since the
     * name is the hash of exactly these bytes.
     */
    public function toBytes(): string
    {
        return DagCbor::encode($this->toArray());
    }

    public static function fromBytes(string $bytes): self
    {
        $decoded = DagCborDecoder::decode($bytes);

        return self::fromArray(is_array($decoded) ? $decoded : throw new RuntimeException('That is not a commit.'));
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
        return (string) $this->prev === (string) $earlier->cid()
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
            if (! isset($commit[$required])) {
                throw new RuntimeException("A commit without [{$required}] is not a commit.");
            }
        }

        $link = static fn (mixed $value): ?Cid => match (true) {
            $value === null => null,
            $value instanceof Cid => $value,
            is_string($value) => Cid::parse($value),
            default => throw new RuntimeException('A commit link must be a link.'),
        };

        return new self(
            (string) $commit['did'],
            // A commit whose root is absent commits to nothing, so it is not a
            // commit however well formed the rest of it is.
            $link($commit['data']) ?? throw new RuntimeException('A commit must name a record tree.'),
            $link($commit['prev'] ?? null),
            (string) $commit['rev'],
            match (true) {
                ! isset($commit['sig']) => null,
                $commit['sig'] instanceof Bytes => $commit['sig'],
                is_string($commit['sig']) => new Bytes($commit['sig']),
                default => throw new RuntimeException('A commit signature must be bytes.'),
            },
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
