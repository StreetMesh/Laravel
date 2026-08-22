<?php

namespace StreetMesh\Protocol;

use RuntimeException;
use SensitiveParameter;

/**
 * An Ed25519 key pair, used to sign what a server or a resident asserts.
 *
 * Signatures are what make a record survive its issuer. A game result stored at
 * a player's home server is worth something years later because it can still be
 * checked against the key the venue published — not because the venue is still
 * around to vouch for it.
 */
final class Ed25519 implements SigningKey
{
    public function multikey(): string
    {
        return Multikey::fromBase64($this->publicKey);
    }

    public function curve(): string
    {
        return 'ed25519';
    }

    public function algorithm(): string
    {
        return 'EdDSA';
    }

    private function __construct(
        private readonly string $publicKey,
        #[SensitiveParameter]
        private readonly string $secretKey,
    ) {}

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(
            publicKey: base64_encode(sodium_crypto_sign_publickey($pair)),
            secretKey: base64_encode(sodium_crypto_sign_secretkey($pair)),
        );
    }

    public static function fromStored(string $publicKey, #[SensitiveParameter] string $secretKey): self
    {
        return new self($publicKey, $secretKey);
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * Sign bytes exactly as given, which is the only way this signs anything.
     *
     * There is deliberately no method here that takes a structure. Signing a
     * structure means the verifier has to rebuild the same bytes from whatever
     * it decoded, and everything between the two — a framework tidying input, a
     * JSON library ordering keys differently, an empty string becoming null —
     * gets a vote it should never have had. Encode first, sign what you encoded.
     */
    public function sign(string $message): string
    {
        $secret = base64_decode($this->secretKey, true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('This signing key is not a usable Ed25519 secret key.');
        }

        return sodium_crypto_sign_detached($message, $secret);
    }

    /**
     * Check a signature over bytes exactly as given.
     *
     * Deliberately static: verification must not require possession of
     * anything, and must work for a document whose issuer this server has never
     * spoken to and which may no longer exist.
     *
     * @param  string  $signature  base64, standard or url-safe
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        $signature = base64_decode(strtr($signature, '-_', '+/'), true);
        $publicKey = base64_decode($publicKey, true);

        if ($signature === false || $publicKey === false) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
