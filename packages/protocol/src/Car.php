<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * The file a repository travels in.
 *
 * A header naming what the file is about, then a flat pile of blocks, each one
 * a fingerprint followed by the bytes it names. Nothing says how the blocks
 * relate — the links inside them do that — so reading one is a matter of
 * indexing everything and then following references from the root.
 *
 * Plain enough that the value is entirely in it being the format everybody else
 * already writes.
 */
final class Car
{
    /**
     * @param  array<int, Cid>  $roots
     * @param  array<string, string>  $blocks  CID => bytes
     */
    private function __construct(
        public readonly array $roots,
        private readonly array $blocks,
    ) {}

    public static function read(string $bytes): self
    {
        $offset = 0;

        $header = DagCborDecoder::decode(self::section($bytes, $offset));

        if (! is_array($header) || ($header['version'] ?? null) !== 1) {
            throw new RuntimeException('That is not a version 1 archive.');
        }

        $blocks = [];

        while ($offset < strlen($bytes)) {
            $block = self::section($bytes, $offset);

            // Each block is its own fingerprint followed by what it names. The
            // fingerprint is not length-prefixed, so it has to be read by shape.
            [$cid, $consumed] = self::readCid($block);

            $blocks[(string) $cid] = substr($block, $consumed);
        }

        return new self($header['roots'] ?? [], $blocks);
    }

    public function has(Cid|string $cid): bool
    {
        return isset($this->blocks[(string) $cid]);
    }

    public function bytes(Cid|string $cid): string
    {
        return $this->blocks[(string) $cid]
            ?? throw new RuntimeException("[{$cid}] is not in this archive.");
    }

    /**
     * A block, decoded — and checked to be what it claims.
     *
     * The check is the reason to bother: a block whose contents do not hash to
     * the name it arrived under is either corrupt or substituted, and either way
     * is not the thing that was asked for.
     */
    public function block(Cid|string $cid): mixed
    {
        $bytes = $this->bytes($cid);

        if ((string) Cid::forBytes($bytes) !== (string) $cid) {
            throw new RuntimeException("The block delivered as [{$cid}] does not hash to that name.");
        }

        return DagCborDecoder::decode($bytes);
    }

    /**
     * @return array<int, string>
     */
    public function cids(): array
    {
        return array_keys($this->blocks);
    }

    public function count(): int
    {
        return count($this->blocks);
    }

    private static function section(string $bytes, int &$offset): string
    {
        $length = self::varint($bytes, $offset);

        if ($offset + $length > strlen($bytes)) {
            throw new RuntimeException('A section runs off the end of the archive.');
        }

        $section = substr($bytes, $offset, $length);
        $offset += $length;

        return $section;
    }

    private static function varint(string $bytes, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($bytes)) {
                throw new RuntimeException('A length runs off the end of the archive.');
            }

            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $value;
    }

    /**
     * @return array{0: Cid, 1: int}
     */
    private static function readCid(string $block): array
    {
        $offset = 0;

        $version = self::varint($block, $offset);
        self::varint($block, $offset);            // codec
        self::varint($block, $offset);            // hash function
        $length = self::varint($block, $offset);  // digest length

        if ($version !== 1) {
            throw new RuntimeException("This reads version 1 identifiers only; found [{$version}].");
        }

        $consumed = $offset + $length;

        return [Cid::fromBytes(substr($block, 0, $consumed)), $consumed];
    }
}
