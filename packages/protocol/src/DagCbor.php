<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;

/**
 * Deterministic CBOR, the encoding ATProtocol hashes and signs.
 *
 * Only the subset a PLC operation needs: null, booleans, integers, strings,
 * lists and maps. No floats, no byte strings, no CID links — those belong to
 * repo records rather than to identity, and guessing at them here would be
 * inventing again.
 *
 * The one rule that matters and is easy to get wrong: map keys sort by length
 * first and only then bytewise, which is RFC 7049's canonical order rather than
 * the plain lexicographic order RFC 8949 later adopted. Get it backwards and
 * every DID you compute is wrong in a way that looks like a signature problem.
 */
final class DagCbor
{
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => "\xf6",
            $value === true => "\xf5",
            $value === false => "\xf4",
            is_int($value) => self::integer($value),
            is_string($value) => self::head(3, strlen($value)).$value,

            // Bytes rather than text, which CBOR distinguishes and PHP does not.
            $value instanceof Bytes => self::head(2, strlen($value->value)).$value->value,

            /*
             * A link. Tag 42, then the identifier as a byte string with a
             * leading zero — the multibase prefix saying "raw bytes, not text".
             * The prefix is required and is the detail most often missed.
             */
            $value instanceof Cid => self::head(6, 42).self::encode(new Bytes("\x00".$value->toBytes())),

            is_array($value) => self::container($value),
            default => throw new InvalidArgumentException(
                'DAG-CBOR here covers null, bool, int, string, bytes, links, lists and maps — not '
                .get_debug_type($value).'.'
            ),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function container(array $value): string
    {
        /*
         * PHP cannot tell an empty list from an empty map and CBOR very much
         * can, so one of them has to be chosen. An empty list wins, because
         * `array_is_list([])` is true and because real documents contain them —
         * a tree node with no entries of its own is ordinary.
         *
         * The cost is that an empty *map* cannot be expressed. Nothing here
         * needs one, and a silent wrong answer would be worse than this
         * sentence, so it is written down rather than discovered.
         */
        if (array_is_list($value)) {
            return self::head(4, count($value)).implode('', array_map(self::encode(...), $value));
        }

        $keys = array_map(strval(...), array_keys($value));

        usort($keys, fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $encoded = self::head(5, count($keys));

        foreach ($keys as $key) {
            $encoded .= self::encode($key).self::encode($value[$key]);
        }

        return $encoded;
    }

    private static function integer(int $value): string
    {
        return $value >= 0
            ? self::head(0, $value)
            : self::head(1, -$value - 1);
    }

    /**
     * A major type and a value, in the shortest form that holds it.
     */
    private static function head(int $major, int $value): string
    {
        $prefix = $major << 5;

        return match (true) {
            $value < 24 => chr($prefix | $value),
            $value < 0x100 => chr($prefix | 24).chr($value),
            $value < 0x10000 => chr($prefix | 25).pack('n', $value),
            $value < 0x100000000 => chr($prefix | 26).pack('N', $value),
            default => chr($prefix | 27).pack('J', $value),
        };
    }
}
