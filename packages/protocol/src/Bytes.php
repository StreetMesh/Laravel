<?php

namespace StreetMesh\Protocol;

use Stringable;

/**
 * A string of bytes, as distinct from a string of text.
 *
 * CBOR keeps them apart and so must anything that produces the same bytes as
 * somebody else. A key written as text where the format expects bytes encodes
 * one byte differently, which changes the hash, which changes every name derived
 * from it — a difference invisible in PHP, where both are just strings, and
 * total once anything is signed.
 */
final class Bytes implements Stringable
{
    public function __construct(public readonly string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
