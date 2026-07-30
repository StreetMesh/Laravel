<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;

/**
 * A public key written the way the DID world writes keys.
 *
 * Our discovery documents publish a raw key as standard base64. Every DID
 * document publishes it as a multibase-encoded multicodec value instead: a
 * varint saying what kind of key this is, then the key, then base58btc over the
 * lot with a `z` to say which base was used.
 *
 * Three curves, because the choice is not ours to make. Ed25519 is what this
 * codebase signs with today; ATProtocol permits neither it nor anything else
 * beyond secp256k1 and P-256, which is one of the more consequential findings
 * of the spike. See SPIKE-DID.md.
 */
final class Multikey
{
    /**
     * Multicodec varints, and the key length each implies.
     *
     * @var array<string, array{prefix: string, bytes: int}>
     */
    private const CURVES = [
        'ed25519' => ['prefix' => "\xed\x01", 'bytes' => 32],
        'secp256k1' => ['prefix' => "\xe7\x01", 'bytes' => 33],
        'p256' => ['prefix' => "\x80\x24", 'bytes' => 33],
    ];

    private const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $publicKey, string $curve = 'ed25519'): string
    {
        $spec = self::CURVES[$curve]
            ?? throw new InvalidArgumentException("[{$curve}] is not a curve this understands.");

        if (strlen($publicKey) !== $spec['bytes']) {
            throw new InvalidArgumentException(
                "A {$curve} public key is {$spec['bytes']} bytes, not ".strlen($publicKey).'.'
            );
        }

        return 'z'.self::base58Encode($spec['prefix'].$publicKey);
    }

    /**
     * The raw key bytes, whatever curve it turns out to be.
     */
    public static function decode(string $multikey): string
    {
        [, $bytes] = self::split($multikey);

        return $bytes;
    }

    /**
     * Which curve a key is on — needed because a verifier has to pick an
     * algorithm before it can check anything.
     */
    public static function curveOf(string $multikey): string
    {
        [$curve] = self::split($multikey);

        return $curve;
    }

    public static function fromBase64(string $publicKey, string $curve = 'ed25519'): string
    {
        $raw = base64_decode($publicKey, true);

        if ($raw === false) {
            throw new InvalidArgumentException('That public key is not base64.');
        }

        return self::encode($raw, $curve);
    }

    public static function toBase64(string $multikey): string
    {
        return base64_encode(self::decode($multikey));
    }

    /**
     * A `did:key`, which is how PLC operations name keys.
     */
    public static function toDidKey(string $publicKey, string $curve): string
    {
        return 'did:key:'.self::encode($publicKey, $curve);
    }

    /**
     * @return array{0: string, 1: string} curve name, raw key bytes
     */
    private static function split(string $multikey): array
    {
        if (! str_starts_with($multikey, 'z')) {
            throw new InvalidArgumentException("[{$multikey}] is not base58btc multibase.");
        }

        $bytes = self::base58Decode(substr($multikey, 1));

        foreach (self::CURVES as $curve => $spec) {
            if (str_starts_with($bytes, $spec['prefix'])) {
                return [$curve, substr($bytes, strlen($spec['prefix']))];
            }
        }

        throw new InvalidArgumentException("[{$multikey}] is not a key type this understands.");
    }

    private static function base58Encode(string $bytes): string
    {
        $number = gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $encoded = '';

        while (gmp_cmp($number, 0) > 0) {
            [$number, $remainder] = gmp_div_qr($number, 58);
            $encoded = self::BASE58[gmp_intval($remainder)].$encoded;
        }

        // A leading zero byte carries no magnitude, so it has to be written out
        // separately or it vanishes in the arithmetic.
        foreach (str_split($bytes) as $byte) {
            if ($byte !== "\x00") {
                break;
            }

            $encoded = '1'.$encoded;
        }

        return $encoded;
    }

    private static function base58Decode(string $encoded): string
    {
        $number = gmp_init(0);

        foreach (str_split($encoded) as $character) {
            $index = strpos(self::BASE58, $character);

            if ($index === false) {
                throw new InvalidArgumentException("[{$character}] is not a base58btc character.");
            }

            $number = gmp_add(gmp_mul($number, 58), $index);
        }

        $bytes = gmp_cmp($number, 0) === 0 ? '' : gmp_export($number, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        foreach (str_split($encoded) as $character) {
            if ($character !== '1') {
                break;
            }

            $bytes = "\x00".$bytes;
        }

        return $bytes;
    }
}
