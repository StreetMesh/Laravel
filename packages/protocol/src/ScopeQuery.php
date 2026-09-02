<?php

namespace StreetMesh\Protocol;

/**
 * The parameters on a scope, with repeated keys kept.
 *
 * Shared by every kind of scope rather than written out beside each one,
 * because the trap below is the sort that is fixed in one copy and left in the
 * other -- and the direction it fails in is quiet enough that nobody would
 * notice which copy they were holding.
 */
final class ScopeQuery
{
    /**
     * Not `parse_str`, which is built for HTML forms and keeps only the last of
     * `action=create&action=delete` unless the name ends in `[]`. This grammar
     * repeats keys deliberately and means all of them, so using PHP's parser
     * here silently narrows a permission -- which is the safe direction to
     * fail, and still wrong.
     *
     * @return array<string, array<int, string>>
     */
    public static function parse(string $query): array
    {
        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $parameters[rawurldecode($name)][] = rawurldecode($value);
        }

        return $parameters;
    }
}
