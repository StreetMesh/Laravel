<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;
use Stringable;

/**
 * An address for a place, and for a spot within that place.
 *
 * The web is a web because of the link. An address that can only name a server
 * gives you a directory of destinations; an address that can name that table in
 * that room, and the seat you are sitting in, gives you sharing, bookmarking,
 * embedding, search, and "meet me here" out of one decision.
 *
 *   https://chess.test/tables/7          the table
 *   https://chess.test/tables/7#white    the seat at it
 *
 * These are ordinary URLs on purpose. A scheme of our own would be a link that
 * does not link: not clickable in a chat app, not followable from a web page,
 * not openable in a browser, not crawlable — which would cost exactly the four
 * things addressing places was for. The spot is a fragment because that is what
 * fragments already mean on the web: a part of the thing at the other end.
 */
final class Address implements Stringable
{
    private function __construct(
        private readonly string $host,
        private readonly string $path,
        private readonly ?string $spot,
    ) {}

    public static function make(string $host, string $path, ?string $spot = null): self
    {
        return new self(strtolower($host), '/'.trim($path, '/'), $spot);
    }

    public static function parse(string $input): self
    {
        $parts = parse_url(trim($input));
        $host = $parts['host'] ?? null;

        if (! $host || ($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException(
                "[{$input}] is not a StreetMesh address. An address looks like https://chess.test/tables/7."
            );
        }

        return self::make($host, $parts['path'] ?? '', $parts['fragment'] ?? null);
    }

    public static function tryParse(string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The spot within the place — a seat, a stool, a square of floor.
     *
     * A fragment is never sent to the server, which is correct for citing where
     * something happened. Directing somebody into a *particular* seat on arrival
     * would need a query parameter instead, because the server has to see it.
     */
    public function spot(): ?string
    {
        return $this->spot;
    }

    public function at(string $spot): self
    {
        return new self($this->host, $this->path, $spot);
    }

    /**
     * The place, without the spot within it.
     */
    public function place(): self
    {
        return new self($this->host, $this->path, null);
    }

    public function __toString(): string
    {
        return 'https://'.$this->host.$this->path
            .($this->spot === null ? '' : '#'.$this->spot);
    }
}
