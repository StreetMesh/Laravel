<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\DagCbor;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\Jws;
use StreetMesh\Protocol\Multikey;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;

/**
 * The vectors are the test suite.
 *
 * This package has almost no tests of its own by design. What it must do is
 * defined in StreetMesh/Protocol, in `conformance/`, as plain JSON that any
 * implementation in any language can be held to — so writing a second, private
 * set of expectations here would be inventing a second definition and then
 * agreeing with ourselves.
 *
 * Fetch them with `composer conformance`, or let CI do it against the pinned
 * revision in `.github/workflows/test.yml`.
 */
class ConformanceTest extends TestCase
{
    private const VECTORS = __DIR__.'/../conformance';

    public static function setUpBeforeClass(): void
    {
        if (! is_dir(self::VECTORS)) {
            self::markTestSkipped(
                'Conformance vectors are absent. Run `composer conformance` to fetch them.'
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function suite(string $file): array
    {
        $path = self::VECTORS.'/'.$file;

        return is_file($path)
            ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
            : ['vectors' => []];
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function dagCborVectors(): iterable
    {
        foreach (self::suite('encoding/dag-cbor.json')['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('dagCborVectors')]
    public function test_dag_cbor_encoding(array $vector): void
    {
        $this->assertSame($vector['hex'], bin2hex(DagCbor::encode($vector['value'])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function multikeyVectors(): iterable
    {
        foreach (self::suite('encoding/multikey.json')['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('multikeyVectors')]
    public function test_multikey_encoding(array $vector): void
    {
        $raw = (string) hex2bin($vector['publicKeyHex']);

        $this->assertSame($vector['multikey'], Multikey::encode($raw, $vector['curve']));
        $this->assertSame($raw, Multikey::decode($vector['multikey']));
        $this->assertSame($vector['curve'], Multikey::curveOf($vector['multikey']));
    }

    /**
     * Real identities, so passing this is agreement with the network rather
     * than with ourselves.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function didPlcVectors(): iterable
    {
        foreach (self::suite('identity/did-plc.json')['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('didPlcVectors')]
    public function test_did_plc_derivation(array $vector): void
    {
        $this->assertSame($vector['did'], Plc::did($vector['operation']));
    }

    /**
     * Ed25519 is deterministic, so this is an equality rather than a check —
     * a stronger thing to be able to assert about a signature format.
     */
    public function test_jws_reproduces_every_vector_exactly(): void
    {
        $suite = self::suite('signing/jws.json');

        $this->assertNotEmpty($suite['vectors']);

        $key = Ed25519::fromStored(
            Multikey::toBase64($suite['publicKeyMultibase']),
            $suite['secretKeyBase64'],
        );

        foreach ($suite['vectors'] as $vector) {
            $this->assertSame(
                $vector['compact'],
                Jws::sign($vector['claims'], $key, $suite['keyId']),
                "JWS vector [{$vector['name']}]",
            );

            // The reason this vector exists: a blank field must survive intact.
            $this->assertSame('', Jws::verify($vector['compact'], $key->publicKey())['detail']['pgn']);
        }
    }

    public function test_p256_signatures_verify_and_reject(): void
    {
        $suite = self::suite('signing/p256.json');

        $this->assertNotEmpty($suite['vectors']);

        $publicKey = (string) hex2bin($suite['publicKeyHex']);
        $verifier = P256::generate();

        foreach ($suite['vectors'] as $vector) {
            $signature = (string) hex2bin($vector['signatureHex']);

            $this->assertTrue(
                $verifier->verify($vector['message'], $signature, $publicKey),
                "P-256 vector [{$vector['name']}] should verify",
            );

            $this->assertFalse(
                $verifier->verify($vector['message'].'!', $signature, $publicKey),
                "P-256 vector [{$vector['name']}] should reject a different message",
            );
        }
    }
}
