<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Proof that whoever redeems an authorization code is whoever asked for one.
 *
 * The venue keeps a secret, sends only its hash when it asks, and produces the
 * secret itself when it redeems. Anything that intercepts the code on its way
 * back through the person's browser cannot spend it, because it cannot produce
 * the secret the code was bound to.
 *
 * Required for every request in this profile, and only in the hashed form —
 * `plain` is forbidden, which is worth knowing because it is the default in
 * several libraries and it makes the whole exercise pointless.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636
 */
final class Pkce
{
    public const METHOD = 'S256';

    private function __construct(
        #[SensitiveParameter]
        public readonly string $verifier,
    ) {}

    /**
     * 32 bytes, which base64url encodes to 43 characters — the shortest length
     * the specification allows, and 256 bits of it.
     */
    public static function generate(): self
    {
        return new self(self::encode(random_bytes(32)));
    }

    /**
     * Rebuild it at the moment of redeeming, from wherever it was kept.
     */
    public static function fromVerifier(#[SensitiveParameter] string $verifier): self
    {
        $length = strlen($verifier);

        if ($length < 43 || $length > 128) {
            throw new InvalidArgumentException('A verifier is between 43 and 128 characters.');
        }

        return new self($verifier);
    }

    /**
     * What travels in the request. The verifier itself never does, until the
     * code is being exchanged.
     */
    public function challenge(): string
    {
        return self::encode(hash('sha256', $this->verifier, binary: true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
