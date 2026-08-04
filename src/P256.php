<?php

namespace StreetMesh\Protocol;

use RuntimeException;
use SensitiveParameter;

/**
 * An ECDSA P-256 key pair, because ATProtocol will not take Ed25519.
 *
 * This codebase signs everything with Ed25519 through libsodium, which is a
 * good choice and not an available one: a PLC operation may name keys only on
 * secp256k1 or P-256. Of those two, P-256 is the one PHP can do without an
 * extension, so it is the practical answer for a PHP implementation.
 *
 * Written out here to answer a question rather than to be kept — the point is
 * to find out whether minting a `did:plc` in PHP is a morning's work or a
 * blocker. See SPIKE-DID.md.
 */
final class P256 implements SigningKey
{
    /**
     * The order of the P-256 group, which decides which half a signature is in.
     */
    private const ORDER = 'FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551';

    public function multikey(): string
    {
        return Multikey::encode($this->publicKey, 'p256');
    }

    public function curve(): string
    {
        return 'p256';
    }

    public function algorithm(): string
    {
        return 'ES256';
    }

    private function __construct(
        private readonly string $publicKey,
        #[SensitiveParameter]
        private readonly string $privateKey,
    ) {}

    public static function generate(): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',

            /*
             * Ignored for an elliptic curve, where the curve decides the size —
             * but PHP defaults it to 0 when it finds no openssl.cnf and then
             * refuses to generate anything smaller than 384. Present only to
             * get past that, and worth a reference implementation writing down
             * so nobody else spends an hour on it.
             */
            'private_key_bits' => 384,
        ]);

        if ($key === false) {
            throw new RuntimeException('OpenSSL would not generate a P-256 key.');
        }

        openssl_pkey_export($key, $pem);

        return new self(self::compress($key), $pem);
    }

    /**
     * Rebuild a key that was put away.
     *
     * @param  string  $publicKey  base64 of the 33 compressed bytes
     * @param  string  $privateKey  the PEM
     */
    public static function fromStored(string $publicKey, #[SensitiveParameter] string $privateKey): self
    {
        $raw = base64_decode($publicKey, true);

        return $raw === false
            ? throw new RuntimeException('That public key is not base64.')
            : new self($raw, $privateKey);
    }

    /**
     * The private half, for putting away. Handle accordingly.
     */
    public function secretKey(): string
    {
        return $this->privateKey;
    }

    /**
     * The public key as the 33 compressed bytes a multikey wants.
     */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function didKey(): string
    {
        return Multikey::toDidKey($this->publicKey, 'p256');
    }

    /**
     * Sign, as ES256: SHA-256, then the raw r‖s pair rather than OpenSSL's DER.
     *
     * JOSE and PLC both want the fixed-width form. OpenSSL only speaks DER, so
     * the conversion is unavoidable and is the fiddliest part of the exercise.
     */
    public function sign(string $message): string
    {
        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false || ! openssl_sign($message, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL would not sign with that key.');
        }

        return self::lowS(self::derToRaw($der));
    }

    /**
     * The half of the signature ATProtocol will accept.
     *
     * For every ECDSA signature `(r, s)` there is an equally valid `(r, n - s)`
     * — the same signature by every mathematical measure, and OpenSSL verifies
     * both without complaint. ATProtocol requires the lower of the two and
     * rejects the other, so half of everything signed here was refused by
     * conformant software: repository commits, records, attestations, and the
     * PLC operation that finally showed it.
     *
     * Intermittently, which is why it went unnoticed for so long. OpenSSL
     * picks `s` at random, so it failed about half the time and never once in
     * our own tests — `verify` below is OpenSSL too, and OpenSSL does not care.
     */
    private static function lowS(string $signature): string
    {
        $order = gmp_init(self::ORDER, 16);
        $s = gmp_init(bin2hex(substr($signature, 32, 32)), 16);

        if (gmp_cmp($s, gmp_div_q($order, 2)) <= 0) {
            return $signature;
        }

        $lowered = gmp_strval(gmp_sub($order, $s), 16);

        return substr($signature, 0, 32).(string) hex2bin(str_pad($lowered, 64, '0', STR_PAD_LEFT));
    }

    public function verify(string $message, string $signature, ?string $publicKey = null): bool
    {
        $pem = self::pemFor($publicKey ?? $this->publicKey);
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }

        return openssl_verify($message, self::rawToDer($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * SEC 1 point compression: the x coordinate, prefixed by whether y is odd.
     */
    private static function compress(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if (! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('That key has no EC coordinates.');
        }

        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return chr(2 + (ord($y[31]) & 1)).$x;
    }

    /**
     * Back to a PEM OpenSSL will read, by wrapping the point in the fixed SPKI
     * header that says "this is a P-256 public key".
     */
    private static function pemFor(string $compressed): string
    {
        $spki = base64_encode(hex2bin('3039301306072a8648ce3d020106082a8648ce3d030107032200').$compressed);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($spki, 64, "\n").'-----END PUBLIC KEY-----';
    }

    private static function derToRaw(string $der): string
    {
        $offset = 4;
        $rLength = ord($der[3]);
        $r = substr($der, $offset, $rLength);

        $offset += $rLength + 2;
        $sLength = ord($der[$offset - 1]);
        $s = substr($der, $offset, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private static function rawToDer(string $raw): string
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
