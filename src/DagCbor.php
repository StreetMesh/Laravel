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
            is_array($value) => self::container($value),
            default => throw new InvalidArgumentException(
                'DAG-CBOR here covers null, bool, int, string, list and map — not '.get_debug_type($value).'.'
            ),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function container(array $value): string
    {
        /*
         * PHP cannot tell an empty list from an empty map, and CBOR very much
         * can. Nothing in a PLC operation is ever empty, so rather than pick a
         * side silently this refuses — the same class of ambiguity that turned
         * a blank string into a null and cost us two days.
         */
        if ($value === []) {
            throw new InvalidArgumentException(
                'An empty array is ambiguous in DAG-CBOR: PHP cannot say whether it is a list or a map.'
            );
        }

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
