<?php

namespace StreetMesh\Server\Domicile\Residents;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Whether somebody may have the address they asked for.
 *
 * Both halves of the question in one place, because at a sign-up form they are
 * one question. A name that DNS could not carry and a name somebody already
 * holds are different failures, but the person typing gets a sentence either
 * way and does not care which it was.
 *
 * The sentences come from Handle itself rather than being written again here.
 * Kept in two places, the rules would drift, and the form would accept a name
 * that then failed when it was actually issued.
 */
final readonly class AvailableAddress implements ValidationRule
{
    public function __construct(private Residents $residents) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $handle = Handle::for((string) $value, $this->residents->host());
        } catch (InvalidArgumentException $refused) {
            $fail($refused->getMessage());

            return;
        }

        if ($this->residents->taken($handle)) {
            $fail('Somebody here already has that address.');
        }
    }
}
