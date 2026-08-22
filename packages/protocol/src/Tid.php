<?php

namespace StreetMesh\Protocol;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * A record key that sorts by the moment it was made.
 *
 * Records need names. Choosing a sequential integer ties a record's name to one
 * database's counter, which cannot survive being moved to another server —
 * exactly the portability the rest of this is built for. Choosing a random
 * identifier is portable but unordered, so listing a person's history in the
 * order it happened means reading and sorting all of it.
 *
 * A TID is both: 64 bits, of which 53 are microseconds since the epoch and 10
 * are a clock identifier, written in a base32 alphabet ordered so that sorting
 * the text sorts the times. Lexical order is chronological order, which means a
 * database index, a directory listing and a paginated feed all agree for free.
 *
 * The clock identifier is what keeps two processes writing in the same
 * microsecond from choosing the same name. It is random per process rather than
 * coordinated, because coordination between servers is the thing being avoided.
 */
final class Tid
{
    /**
     * Ordered so that ASCII order matches numeric order — which is the entire
     * point, and the reason this is not RFC 4648's alphabet.
     */
    private const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    private const LENGTH = 13;

    private const CLOCK_BITS = 10;

    /** Set once per process, so keys from one writer never collide. */
    private static ?int $clockId = null;

    /** The last one issued here, so a fast writer still moves forward. */
    private static int $lastMicroseconds = 0;

    private function __construct(public readonly string $value) {}

    public static function now(): self
    {
        self::$clockId ??= random_int(0, (1 << self::CLOCK_BITS) - 1);

        $microseconds = (int) (microtime(true) * 1_000_000);

        /*
         * Two records made inside the same microsecond, or a clock that has
         * gone backwards, would otherwise produce a key that sorts wrongly or
         * not at all. Stepping forward keeps the ordering honest at the cost of
         * a key that is fractionally ahead of the wall clock.
         */
        $microseconds = max($microseconds, self::$lastMicroseconds + 1);

        self::$lastMicroseconds = $microseconds;

        return self::fromParts($microseconds, self::$clockId);
    }

    public static function fromParts(int $microseconds, int $clockId): self
    {
        $value = ($microseconds << self::CLOCK_BITS) | ($clockId & ((1 << self::CLOCK_BITS) - 1));

        $encoded = '';

        for ($shift = (self::LENGTH - 1) * 5; $shift >= 0; $shift -= 5) {
            $encoded .= self::ALPHABET[($value >> $shift) & 31];
        }

        return new self($encoded);
    }

    public static function parse(string $value): self
    {
        if (strlen($value) !== self::LENGTH || strspn($value, self::ALPHABET) !== self::LENGTH) {
            throw new InvalidArgumentException("[{$value}] is not a record key.");
        }

        return new self($value);
    }

    /**
     * When this key was made, which is readable from the key itself and needs
     * no lookup.
     */
    public function at(): DateTimeImmutable
    {
        $microseconds = $this->toInteger() >> self::CLOCK_BITS;

        $at = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', intdiv($microseconds, 1_000_000), $microseconds % 1_000_000),
        );

        // The format string is built here from integers, so this cannot fail —
        // but saying so out loud beats a cast that hides it if it ever does.
        return $at ?: throw new RuntimeException("[{$this->value}] decodes to a time PHP will not accept.");
    }

    public function clockId(): int
    {
        return $this->toInteger() & ((1 << self::CLOCK_BITS) - 1);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private function toInteger(): int
    {
        $value = 0;

        foreach (str_split($this->value) as $character) {
            $value = ($value << 5) | strpos(self::ALPHABET, $character);
        }

        return $value;
    }
}
