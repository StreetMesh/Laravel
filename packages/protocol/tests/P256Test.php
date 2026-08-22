<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\P256;

/**
 * The curve everything on this network signs with.
 *
 * There was no test file here at all, which is how the defect below survived:
 * P-256 was exercised everywhere and asserted nowhere, and the one property
 * that mattered is invisible to a round trip.
 */
class P256Test extends TestCase
{
    /**
     * The order of the P-256 group. A signature is "low-S" when its `s` is in
     * the lower half of it.
     */
    private const ORDER = 'FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551';

    /**
     * Every ECDSA signature has a twin.
     *
     * For any `(r, s)` there is an equally valid `(r, n - s)`, and OpenSSL
     * verifies both without complaint. ATProtocol requires the lower one and
     * rejects the other — so half of everything signed here was refused by
     * conformant software: repository commits, records, attestations, and the
     * PLC operations that finally made it visible.
     *
     * Nothing caught it because signing and verifying are both OpenSSL, and a
     * round trip therefore passes on a signature the network will not take.
     * Thirty of these would have found it in under a second.
     */
    public function test_every_signature_is_one_atprotocol_will_accept(): void
    {
        $order = gmp_init(self::ORDER, 16);
        $half = gmp_div_q($order, 2);
        $key = P256::generate();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $signature = $key->sign('something to sign '.$attempt);
            $s = gmp_init(bin2hex(substr($signature, 32, 32)), 16);

            $this->assertLessThanOrEqual(
                0,
                gmp_cmp($s, $half),
                'a high-S signature is valid arithmetic and is refused by every ATProtocol implementation',
            );
        }
    }

    public function test_a_signature_still_verifies_after_being_lowered(): void
    {
        $key = P256::generate();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $message = 'something to sign '.$attempt;

            $this->assertTrue($key->verify($message, $key->sign($message)));
        }
    }

    public function test_a_signature_is_the_fixed_width_the_wire_format_expects(): void
    {
        $key = P256::generate();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $this->assertSame(64, strlen($key->sign('something to sign '.$attempt)));
        }
    }

    public function test_somebody_elses_signature_is_refused(): void
    {
        $key = P256::generate();
        $other = P256::generate();

        $this->assertFalse($key->verify('a message', $other->sign('a message')));
    }
}
