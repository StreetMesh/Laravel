<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * Checking a signature without being told what kind it is.
 *
 * A verifier does not get to choose the curve — whoever signed did that, and
 * said so in the key they published. Three are in use on the network, and an
 * implementation that handles only its own favourite can read only its own
 * documents.
 *
 * Signing stays elsewhere, per curve, because signing requires a private key and
 * a deliberate choice. Verification is the promiscuous half: it must accept
 * anything the network produces.
 */
final class Signature
{
    /**
     * The fixed DER preamble that turns a bare curve point into a key OpenSSL
     * will read. One per curve, and none of them are guessable.
     */
    private const SPKI = [
        'p256' => '3039301306072a8648ce3d020106082a8648ce3d030107032200',
        'secp256k1' => '3036301006072a8648ce3d020106052b8104000a032200',
    ];

    /**
     * @param  string  $multikey  the key as its owner published it, curve and all
     * @param  string  $message  the bytes that were signed, exactly as signed
     * @param  string  $signature  raw — 64 bytes for all three curves
     */
    public static function verify(string $multikey, string $message, string $signature): bool
    {
        $curve = Multikey::curveOf($multikey);
        $key = Multikey::decode($multikey);

        return match ($curve) {
            'ed25519' => Ed25519::verify($message, base64_encode($signature), base64_encode($key)),
            'p256', 'secp256k1' => self::ecdsa($curve, $key, $message, $signature),
            default => throw new RuntimeException("No way to check a [{$curve}] signature."),
        };
    }

    /**
     * Can a signature on this curve be checked here at all?
     *
     * Asked separately because "we cannot check this" and "this does not check
     * out" are different answers, and reporting the first as the second would
     * be calling a document forged because of a missing dependency.
     */
    public static function supports(string $curve): bool
    {
        return $curve === 'ed25519'
            || (isset(self::SPKI[$curve]) && in_array(
                $curve === 'p256' ? 'prime256v1' : $curve,
                openssl_get_curve_names() ?: [],
                strict: true,
            ));
    }

    private static function ecdsa(string $curve, string $point, string $message, string $signature): bool
    {
        if (! self::supports($curve)) {
            throw new RuntimeException(
                "This build of OpenSSL cannot do [{$curve}], so that signature can be neither "
                .'confirmed nor denied.'
            );
        }

        $spki = (string) hex2bin(self::SPKI[$curve]).$point;

        $key = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----'
        );

        if ($key === false) {
            return false;
        }

        /*
         * The signature travels as the fixed-width r‖s pair that JOSE and this
         * protocol both use; OpenSSL wants DER. The conversion is the same one
         * P256 does for signing, in reverse.
         */
        return openssl_verify($message, self::der($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function der(string $raw): string
    {
        $r = self::derInteger(substr($raw, 0, 32));
        $s = self::derInteger(substr($raw, 32, 32));

        return "\x30".chr(strlen($r.$s)).$r.$s;
    }

    private static function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        // DER integers are signed, so a leading bit of 1 needs a zero byte in
        // front of it or the value reads as negative.
        if ($value === '' || ord($value[0]) > 0x7F) {
            $value = "\x00".$value;
        }

        return "\x02".chr(strlen($value)).$value;
    }
}
