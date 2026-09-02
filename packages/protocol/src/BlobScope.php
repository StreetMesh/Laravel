<?php

namespace StreetMesh\Protocol;

/**
 * Permission to put bytes in somebody's repository.
 *
 * A sibling of `Scope` rather than a widening of it, because the two answer
 * different questions and a class that answered both would be asked the wrong
 * one. `repo:` decides whether a *record* of some kind may be written; this
 * decides whether *bytes* of some kind may be kept. A venue may perfectly well
 * be allowed one and not the other, and a resident reading a consent screen
 * should be able to see which.
 *
 * That separation is also what `Scope::parse` already assumes: it returns null
 * for anything that is not `repo:`, on the stated grounds that `blob:*\/*` is a
 * perfectly good scope that simply does not decide whether a record may be
 * written. This is the other half of that sentence, and it returns null for
 * everything that is not `blob:` for the same reason.
 *
 *   blob:*\/*                                   any bytes
 *   blob:model/gltf-binary                      one type
 *   blob:image/*                                a family
 *   blob?accept=image/png&accept=model/gltf-binary
 *
 * Until this existed there was no upload endpoint, and that was the right way
 * round: a permission the endpoint cannot enforce and the consent screen cannot
 * describe is not a permission. The scope, the enforcement and the sentence
 * arrive together.
 *
 * @see https://atproto.com/specs/permission
 */
final class BlobScope
{
    /** What a pattern says when it means "anything at all". */
    public const ANY = '*/*';

    /**
     * @param  array<int, string>  $accepts  mime patterns, lowercased
     */
    private function __construct(public readonly array $accepts) {}

    /**
     * @param  array<int, string>  $mimes
     */
    public static function forTypes(array $mimes): self
    {
        $mimes = array_map(strtolower(...), array_map(trim(...), $mimes));

        return new self(array_values(array_unique(array_filter($mimes, fn (string $m): bool => $m !== ''))));
    }

    /**
     * Read one as it arrived, or null if it is not a blob scope at all.
     *
     * Null rather than an exception, for the reason `Scope` gives: a token
     * carries several scopes and the ones this does not understand are somebody
     * else's business rather than an error.
     */
    public static function parse(string $scope): ?self
    {
        [$head, $query] = array_pad(explode('?', $scope, 2), 2, '');
        [$resource, $positional] = array_pad(explode(':', $head, 2), 2, null);

        if ($resource !== 'blob') {
            return null;
        }

        $parameters = ScopeQuery::parse($query);

        $accepts = $positional !== null && $positional !== ''
            ? [$positional]
            : ($parameters['accept'] ?? []);

        $parsed = self::forTypes($accepts);

        /*
         * A blob scope naming nothing permits nothing. Reading it as "anything"
         * would make the emptiest possible permission the widest one, which is
         * the one mistake in a permission grammar that cannot be recovered from
         * by being careful elsewhere.
         *
         * Judged after the types have been cleaned up rather than before, so
         * that `blob?accept=` -- a key with no value, which is what a caller
         * building a URL out of an empty variable produces -- is the same
         * answer as `blob` with no key at all.
         */
        return $parsed->accepts === [] ? null : $parsed;
    }

    /**
     * May a token carrying these scopes store bytes of this type?
     *
     * @param  array<int, string>  $granted  every scope on the token
     */
    public static function permits(array $granted, string $mime): bool
    {
        foreach ($granted as $scope) {
            if (self::parse($scope)?->accepts($mime) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type this was asked about, against the patterns that were granted.
     *
     * Lowercased on both sides because media types are case-insensitive, and
     * any parameters after a semicolon are dropped: `image/png; charset=utf-8`
     * is a PNG, and a permission that missed it because of a trailing word
     * would be refusing on a technicality nobody wrote down.
     */
    public function accepts(string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        [$type, $subtype] = array_pad(explode('/', $mime, 2), 2, '');

        if ($type === '' || $subtype === '') {
            return false;
        }

        foreach ($this->accepts as $pattern) {
            if ($pattern === self::ANY || $pattern === $mime || $pattern === $type.'/*') {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        /*
         * The positional form for a single type, because that is what the
         * examples in the specification look like and what a person reading a
         * consent screen has the best chance of recognizing.
         */
        return count($this->accepts) === 1
            ? 'blob:'.$this->accepts[0]
            : 'blob?'.implode('&', array_map(fn (string $a): string => 'accept='.$a, $this->accepts));
    }
}
