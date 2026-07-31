<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;

/**
 * A record's name, derived from what it says.
 *
 * Content addressing: the identifier is the hash of the content, so two copies
 * of the same record have the same name everywhere, and a record that has been
 * altered has a different name than the one anybody cited. That is what makes a
 * reference between records dependable — pointing at an address and a CID
 * together means "that record, as it was", which a mutable pointer cannot say.
 *
 * The encoding is layered, and each layer names the next: a version, then which
 * codec the bytes are in, then which hash function, then how long the digest is,
 * then the digest. Nothing has to be agreed out of band, which is the point.
 *
 *   b afyrei gp6shzy6dlcxuowwoxz7u5nemdrkad2my5zwzpwilcnhih7bw6zm
 *   │ └── version 1, dag-cbor, sha2-256, 32 bytes ──────────────┘
 *   └── base32, lower case
 */
final class Cid
{
    /** CIDv1. */
    private const VERSION = "\x01";

    /** Multicodec `dag-cbor`. */
    private const DAG_CBOR = "\x71";

    /** Multihash `sha2-256`, digest length 32. */
    private const SHA256 = "\x12\x20";

    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    private function __construct(public readonly string $value) {}

    /**
     * The name of a record, given the record.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function forRecord(array $value): self
    {
        return self::forBytes(DagCbor::encode($value));
    }

    public static function forBytes(string $bytes): self
    {
        $digest = hash('sha256', $bytes, binary: true);

        return new self('b'.self::base32(self::VERSION.self::DAG_CBOR.self::SHA256.$digest));
    }

    /**
     * A CID as it travels inside binary formats, rather than as text.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self('b'.self::base32($bytes));
    }

    /**
     * Back to the bytes, for writing into one.
     */
    public function toBytes(): string
    {
        return self::VERSION.self::DAG_CBOR.self::SHA256.$this->digest();
    }

    public function digest(): string
    {
        $prefix = strlen(self::VERSION.self::DAG_CBOR.self::SHA256);

        return substr(self::base32Decode(substr($this->value, 1)), $prefix);
    }

    private static function base32Decode(string $encoded): string
    {
        $bytes = '';
        $buffer = 0;
        $pending = 0;

        foreach (str_split($encoded) as $character) {
            $index = strpos(self::BASE32, $character);

            if ($index === false) {
                throw new InvalidArgumentException("[{$character}] is not a base32 character.");
            }

            $buffer = ($buffer << 5) | $index;
            $pending += 5;

            if ($pending >= 8) {
                $pending -= 8;
                $bytes .= chr(($buffer >> $pending) & 255);
            }
        }

        return $bytes;
    }

    public static function parse(string $value): self
    {
        if (! str_starts_with($value, 'bafyrei') || strlen($value) !== 59) {
            throw new InvalidArgumentException(
                "[{$value}] is not a CIDv1 dag-cbor sha2-256 identifier, which is the only kind used here."
            );
        }

        return new self($value);
    }

    /**
     * Does this record still say what it said when it was named?
     *
     * @param  array<array-key, mixed>  $value
     */
    public function matches(array $value): bool
    {
        return hash_equals($this->value, self::forRecord($value)->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * RFC 4648 base32, lower case and unpadded, as multibase `b`.
     */
    private static function base32(string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $pending = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $pending += 8;

            while ($pending >= 5) {
                $pending -= 5;
                $encoded .= self::BASE32[($buffer >> $pending) & 31];
            }
        }

        if ($pending > 0) {
            $encoded .= self::BASE32[($buffer << (5 - $pending)) & 31];
        }

        return $encoded;
    }
}
