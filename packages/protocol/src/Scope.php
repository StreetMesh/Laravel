<?php

namespace StreetMesh\Protocol;

/**
 * What a permission actually permits.
 *
 * Our prototype had `records:write`, which was wrong in a way worth naming: not
 * merely coarse, but *ours*. A word invented locally is a word no other server
 * on the network understands, and a permission nobody else can interpret is not
 * federation — it is two programs agreeing privately.
 *
 * So this is ATProtocol's grammar rather than one of our own:
 *
 *   repo:com.streetmesh.games.chess                       everything, that type
 *   repo:com.streetmesh.games.chess?action=create         only ever adding
 *   repo:*                                                every type
 *   repo?collection=a.b.c&collection=d.e.f                several at once
 *
 * A venue that only ever writes a finished game should ask for `action=create`
 * and nothing more, and a resident reading the request should be able to see
 * that it cannot go back and change what it wrote.
 *
 * @see https://atproto.com/specs/permission
 */
final class Scope
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const ACTIONS = [self::CREATE, self::UPDATE, self::DELETE];

    /**
     * @param  array<int, string>  $collections  NSIDs, or ['*']
     * @param  array<int, string>  $actions
     */
    private function __construct(
        public readonly array $collections,
        public readonly array $actions,
    ) {}

    /**
     * @param  array<int, string>  $collections
     * @param  array<int, string>  $actions  empty means all of them
     */
    public static function forRepo(array $collections, array $actions = []): self
    {
        return new self(
            array_values($collections),
            $actions === [] ? self::ACTIONS : array_values(array_intersect(self::ACTIONS, $actions)),
        );
    }

    /**
     * Read one as it arrived, or null if it is not a repo scope at all.
     *
     * Null rather than an exception, because a token carries several scopes and
     * the ones this does not understand are somebody else's business rather
     * than an error — `blob:*\/*` and `identity:*` are perfectly good scopes
     * that simply do not decide whether a record may be written.
     */
    public static function parse(string $scope): ?self
    {
        [$head, $query] = array_pad(explode('?', $scope, 2), 2, '');
        [$resource, $positional] = array_pad(explode(':', $head, 2), 2, null);

        if ($resource !== 'repo') {
            return null;
        }

        $parameters = self::query($query);

        $collections = $positional !== null && $positional !== ''
            ? [$positional]
            : ($parameters['collection'] ?? []);

        if ($collections === []) {
            return null;
        }

        return self::forRepo($collections, $parameters['action'] ?? []);
    }

    /**
     * Repeated keys, kept.
     *
     * Not `parse_str`, which is built for HTML forms and keeps only the last of
     * `action=create&action=delete` unless the name ends in `[]`. This grammar
     * repeats keys deliberately and means all of them, so using PHP's parser
     * here silently narrows a permission — which is the safe direction to fail,
     * and still wrong.
     *
     * @return array<string, array<int, string>>
     */
    private static function query(string $query): array
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

    /**
     * May a token carrying these scopes do this to this kind of record?
     *
     * @param  array<int, string>  $granted  every scope on the token
     */
    public static function permits(array $granted, string $collection, string $action): bool
    {
        foreach ($granted as $scope) {
            $parsed = self::parse($scope);

            if ($parsed?->allows($collection, $action) === true) {
                return true;
            }
        }

        return false;
    }

    public function allows(string $collection, string $action): bool
    {
        return (in_array('*', $this->collections, strict: true) || in_array($collection, $this->collections, strict: true))
            && in_array($action, $this->actions, strict: true);
    }

    public function __toString(): string
    {
        /*
         * The positional form for a single collection, because that is what the
         * examples in the specification look like and what a person reading a
         * consent screen has the best chance of recognizing.
         */
        $head = count($this->collections) === 1
            ? 'repo:'.$this->collections[0]
            : 'repo?'.implode('&', array_map(fn (string $c): string => 'collection='.$c, $this->collections));

        if ($this->actions === self::ACTIONS) {
            return $head;
        }

        return $head
            .(str_contains($head, '?') ? '&' : '?')
            .implode('&', array_map(fn (string $a): string => 'action='.$a, $this->actions));
    }
}
