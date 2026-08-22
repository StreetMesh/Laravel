<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * Reading DAG-CBOR, which until now this could only write.
 *
 * Writing was enough while everything produced here was also verified here.
 * Reading is what interoperability actually requires: another server's
 * repository arrives as bytes somebody else encoded, and nothing can be checked
 * until it can be taken apart.
 *
 * Strict on purpose. DAG-CBOR permits exactly one encoding of any value, so a
 * decoder that accepts sloppy input is a decoder that will disagree with the
 * encoder about what a document says — and the whole point of the format is
 * that two implementations cannot disagree.
 */
final class DagCborDecoder
{
    private int $offset = 0;

    private function __construct(private readonly string $bytes) {}

    public static function decode(string $bytes): mixed
    {
        $decoder = new self($bytes);
        $value = $decoder->read();

        if ($decoder->offset !== strlen($bytes)) {
            throw new RuntimeException('There are bytes after the end of that value.');
        }

        return $value;
    }

    /**
     * Decode one value and say where it ended, for readers walking a stream.
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeFirst(string $bytes): array
    {
        $decoder = new self($bytes);

        return [$decoder->read(), $decoder->offset];
    }

    private function read(): mixed
    {
        $initial = $this->byte();
        $major = $initial >> 5;
        $info = $initial & 31;

        return match ($major) {
            0 => $this->length($info),
            1 => -1 - $this->length($info),
            2 => new Bytes($this->take($this->length($info))),
            3 => $this->take($this->length($info)),
            4 => $this->list($this->length($info)),
            5 => $this->map($this->length($info)),
            6 => $this->tagged($this->length($info)),
            7 => match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new RuntimeException("DAG-CBOR has no simple value [{$info}]."),
            },
            default => throw new RuntimeException("Unknown CBOR major type [{$major}]."),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function list(int $count): array
    {
        $list = [];

        for ($i = 0; $i < $count; $i++) {
            $list[] = $this->read();
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(int $count): array
    {
        $map = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $this->read();

            if (! is_string($key)) {
                throw new RuntimeException('DAG-CBOR map keys must be strings.');
            }

            $map[$key] = $this->read();
        }

        return $map;
    }

    /**
     * The only tag DAG-CBOR allows is 42, which means "this is a link".
     */
    private function tagged(int $tag): Cid
    {
        if ($tag !== 42) {
            throw new RuntimeException("DAG-CBOR allows only tag 42; found [{$tag}].");
        }

        $bytes = $this->read();

        if (! $bytes instanceof Bytes || $bytes->value === '' || $bytes->value[0] !== "\x00") {
            /*
             * A link is carried as a byte string beginning with a zero — the
             * multibase prefix meaning "these are raw bytes, not text". Its
             * absence means whatever this is, it is not a link.
             */
            throw new RuntimeException('A DAG-CBOR link must be a byte string with the identity multibase prefix.');
        }

        return Cid::fromBytes(substr($bytes->value, 1));
    }

    private function length(int $info): int
    {
        return match (true) {
            $info < 24 => $info,
            $info === 24 => $this->byte(),
            $info === 25 => $this->integer('n', 2),
            $info === 26 => $this->integer('N', 4),
            $info === 27 => $this->integer('J', 8),
            default => throw new RuntimeException("CBOR length [{$info}] is not permitted in DAG-CBOR."),
        };
    }

    private function integer(string $format, int $width): int
    {
        $unpacked = unpack($format, $this->take($width));

        return $unpacked === false
            ? throw new RuntimeException('That length could not be read.')
            : (int) $unpacked[1];
    }

    private function byte(): int
    {
        return ord($this->take(1));
    }

    private function take(int $count): string
    {
        if ($this->offset + $count > strlen($this->bytes)) {
            throw new RuntimeException('That value runs off the end of the input.');
        }

        $slice = substr($this->bytes, $this->offset, $count);
        $this->offset += $count;

        return $slice;
    }
}
