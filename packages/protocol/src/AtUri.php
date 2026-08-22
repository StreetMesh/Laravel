<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;
use Stringable;

/**
 * The address of a record: whose it is, what kind, and which one.
 *
 *   at://did:plc:z72i7hdynmk6r22z27h6tvur/com.streetmesh.games.chess/3mqcp5qjdfs26
 *   └── authority ──────────────────────┘└── collection ───────────┘└── key ────┘
 *
 * Three parts, and each is doing something the others cannot. The authority is a
 * DID rather than a hostname, so the address survives its subject moving server
 * — a URL would not. The collection is the schema's name, so what a record means
 * is part of where it lives rather than a field inside it. The key sorts by
 * time, so a range of addresses is also a range of moments.
 *
 * A record can therefore name another record without either of them knowing
 * where the other is stored, which is what makes a correction, a refund or a
 * dispute expressible at all: the second fact is about the first, and says so.
 *
 * Deliberately not an https URL, unlike the address of a *place*. A place is
 * something a person visits and should be clickable; a record is something a
 * program resolves, and pinning it to a hostname would undo the portability the
 * DID is there to provide.
 */
final class AtUri implements Stringable
{
    private function __construct(
        public readonly string $authority,
        public readonly ?string $collection,
        public readonly ?string $rkey,
    ) {}

    public static function make(string $authority, ?string $collection = null, ?string $rkey = null): self
    {
        if ($collection === null && $rkey !== null) {
            throw new InvalidArgumentException('A record key without a collection names nothing.');
        }

        return new self($authority, $collection, $rkey);
    }

    public static function parse(string $uri): self
    {
        if (! str_starts_with($uri, 'at://')) {
            throw new InvalidArgumentException("[{$uri}] is not an at:// address.");
        }

        $parts = explode('/', substr($uri, strlen('at://')));

        $authority = array_shift($parts) ?: throw new InvalidArgumentException("[{$uri}] names no subject.");

        if (count($parts) > 2) {
            throw new InvalidArgumentException("[{$uri}] has more parts than an address has.");
        }

        return new self(
            $authority,
            $parts[0] ?? null,
            $parts[1] ?? null,
        );
    }

    public static function tryParse(string $uri): ?self
    {
        try {
            return self::parse($uri);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The whole of somebody's records of one kind.
     */
    public function collection(string $collection): self
    {
        return new self($this->authority, $collection, null);
    }

    public function record(string $rkey): self
    {
        return new self(
            $this->authority,
            $this->collection ?? throw new InvalidArgumentException('That address names no collection.'),
            $rkey,
        );
    }

    /**
     * Does this address a single record, or a set of them?
     */
    public function isRecord(): bool
    {
        return $this->rkey !== null;
    }

    public function __toString(): string
    {
        return 'at://'.implode('/', array_filter([
            $this->authority,
            $this->collection,
            $this->rkey,
        ], fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
