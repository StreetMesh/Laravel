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
 *
 * Two codecs are used here, and the difference is visible in the name. A record
 * is `dag-cbor`, and reads `bafyrei…`. A blob — an image, a model, anything
 * whose bytes are not a structure — is `raw`, and reads `bafkrei…`.
 *
 * That distinction is worth being careful about because getting it wrong is
 * invisible. Hashing image bytes and then labelling them `dag-cbor` produces a
 * name of exactly the right shape and length, which this codebase would go on
 * accepting forever and which no other implementation on the network would
 * agree with.
 */
final class Cid
{
    /** CIDv1. */
    private const VERSION = "\x01";

    /** Multicodec `dag-cbor`, for a record: bytes that are a structure. */
    private const DAG_CBOR = "\x71";

    /** Multicodec `raw`, for a blob: bytes that are only themselves. */
    private const RAW = "\x55";

    /** Multihash `sha2-256`, digest length 32. */
    private const SHA256 = "\x12\x20";

    /** Version, codec, hash, length — four bytes, whichever codec it is. */
    private const PREFIX = 4;

    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * The codec travels with the instance rather than being assumed, so that
     * `toBytes` cannot quietly relabel a blob as a record on the way out.
     */
    private function __construct(
        public readonly string $value,
        private readonly string $codec = self::DAG_CBOR,
    ) {}

    /**
     * The name of a record, given the record.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function forRecord(array $value): self
    {
        return self::forBytes(DagCbor::encode($value));
    }

    /**
     * The name of some DAG-CBOR, given the encoded bytes.
     */
    public static function forBytes(string $bytes): self
    {
        return self::under(self::DAG_CBOR, $bytes);
    }

    /**
     * The name of a blob, given the blob.
     *
     * Bytes that are not a structure and are never going to be decoded as one:
     * an image, a model, a file somebody uploaded. ATProtocol names these under
     * `raw`, and a name is only worth anything if everybody derives it the same
     * way, so this is their arithmetic rather than a convenience of ours.
     */
    public static function forRaw(string $bytes): self
    {
        return self::under(self::RAW, $bytes);
    }

    private static function under(string $codec, string $bytes): self
    {
        $digest = hash('sha256', $bytes, binary: true);

        return new self('b'.self::base32(self::VERSION.$codec.self::SHA256.$digest), $codec);
    }

    /**
     * A CID as it travels inside binary formats, rather than as text.
     *
     * The codec is read out of the bytes rather than assumed, because these
     * arrive from somebody else's encoder and what they say they are is the
     * only thing there is to go on.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self('b'.self::base32($bytes), self::codecIn($bytes));
    }

    /**
     * Back to the bytes, for writing into one.
     */
    public function toBytes(): string
    {
        return self::VERSION.$this->codec.self::SHA256.$this->digest();
    }

    public function digest(): string
    {
        return substr(self::base32Decode(substr($this->value, 1)), self::PREFIX);
    }

    /**
     * Which codec a CID's own bytes claim, refusing anything else.
     *
     * Two are used here and a third would name a record this codebase cannot
     * read, so it is better to say so at the edge than to carry an unknown
     * byte around and find out later.
     */
    private static function codecIn(string $bytes): string
    {
        $codec = substr($bytes, 1, 1);

        return match ($codec) {
            self::DAG_CBOR, self::RAW => $codec,
            default => throw new InvalidArgumentException(
                'That identifier names a codec other than dag-cbor or raw, which is not one used here.'
            ),
        };
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

    /**
     * A CID read back from text.
     *
     * The prefix says which of the two it is: `bafyrei…` is a record, and
     * `bafkrei…` is a blob. Both are CIDv1 sha2-256 and both are 59 characters,
     * so the codec is the whole of what distinguishes them.
     */
    public static function parse(string $value): self
    {
        $codec = match (true) {
            str_starts_with($value, 'bafyrei') => self::DAG_CBOR,
            str_starts_with($value, 'bafkrei') => self::RAW,
            default => null,
        };

        if ($codec === null || strlen($value) !== 59) {
            throw new InvalidArgumentException(
                "[{$value}] is not a CIDv1 sha2-256 identifier under dag-cbor or raw, "
                .'which are the only kinds used here.'
            );
        }

        return new self($value, $codec);
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

    /**
     * And the same question for a blob.
     *
     * Separate rather than an overload, because the two cannot be compared to
     * each other: a blob's name is over its bytes and a record's is over its
     * encoding, so one answering "no" about the other would be true and
     * useless.
     */
    public function matchesBytes(string $bytes): bool
    {
        return hash_equals($this->value, self::forRaw($bytes)->value);
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
