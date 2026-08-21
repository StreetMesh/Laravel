<?php

namespace StreetMesh\Protocol\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\DagCbor;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\Jws;
use StreetMesh\Protocol\Multikey;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\Tid;

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

    private const ABSENT = 'Conformance vectors are absent. Run `composer conformance` to fetch them.';

    /**
     * The vectors are fetched on demand and gitignored, so a fresh checkout not
     * having them is ordinary rather than broken, and this whole class stands
     * down. It does not cover the data-provided tests below — see `named()`.
     */
    public static function setUpBeforeClass(): void
    {
        if (! is_dir(self::VECTORS)) {
            self::markTestSkipped(self::ABSENT);
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
     * Every vector in a file, each under its own name — or one empty case.
     *
     * The empty case is what keeps an absent checkout from failing here.
     * Providers run before `setUpBeforeClass`, so its skip cannot save them,
     * and a provider cannot skip on its own behalf either: PHPUnit calls it
     * before the test exists, so anything it raises — `markTestSkipped`
     * included — is reported as an invalid provider, and yielding nothing at
     * all is reported the same way. That is why only the data-provided tests
     * ever failed while the ones reading their vectors inline skipped fine.
     *
     * So the provider always hands over at least one case, and the skip is
     * raised where it works: inside the test. This also covers the odd case of
     * a vectors directory that exists but is missing one file, which
     * `setUpBeforeClass` would wave through.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    private static function named(string $file): array
    {
        $cases = [];

        foreach (self::suite($file)['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases !== [] ? $cases : ['no vectors' => [[]]];
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    private function skipWithoutVectors(array $vector): void
    {
        if ($vector === []) {
            $this->markTestSkipped(self::ABSENT);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function dagCborVectors(): array
    {
        return self::named('encoding/dag-cbor.json');
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('dagCborVectors')]
    public function test_dag_cbor_encoding(array $vector): void
    {
        $this->skipWithoutVectors($vector);

        $this->assertSame($vector['hex'], bin2hex(DagCbor::encode($vector['value'])));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function multikeyVectors(): array
    {
        return self::named('encoding/multikey.json');
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('multikeyVectors')]
    public function test_multikey_encoding(array $vector): void
    {
        $this->skipWithoutVectors($vector);

        $raw = (string) hex2bin($vector['publicKeyHex']);

        $this->assertSame($vector['multikey'], Multikey::encode($raw, $vector['curve']));
        $this->assertSame($raw, Multikey::decode($vector['multikey']));
        $this->assertSame($vector['curve'], Multikey::curveOf($vector['multikey']));
    }

    /**
     * Real identities, so passing this is agreement with the network rather
     * than with ourselves.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function didPlcVectors(): array
    {
        return self::named('identity/did-plc.json');
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('didPlcVectors')]
    public function test_did_plc_derivation(array $vector): void
    {
        $this->skipWithoutVectors($vector);

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
            $this->assertSame('', Jws::verify($vector['compact'], $key->multikey())['detail']['pgn']);
        }
    }

    /**
     * Which key was current when, rather than which is current now.
     */
    public function test_key_history_matches_every_vector(): void
    {
        $vectors = self::suite('identity/key-history.json')['vectors'];

        $this->assertNotEmpty($vectors);

        foreach ($vectors as $vector) {
            $this->assertSame(
                $vector['history'],
                Plc::keyHistory($vector['auditLog'], $vector['fragment']),
                "key history for [{$vector['name']}]",
            );

            foreach ($vector['queries'] as $query) {
                $at = new DateTimeImmutable($query['at']);

                /*
                 * Caught rather than expected, so that one refusal does not end
                 * the loop and quietly skip every query listed after it.
                 */
                try {
                    $answer = Plc::keyAt($vector['auditLog'], $at, $vector['fragment']);
                } catch (InvalidArgumentException) {
                    $answer = null;
                }

                // A refusal is the correct answer outside the identity's
                // lifetime: returning the earliest key would validate anything
                // backdated to before it existed.
                $this->assertSame(
                    $query['key'],
                    $answer,
                    "key at [{$query['name']}]",
                );
            }
        }
    }

    /**
     * Record keys, whose whole purpose is that sorting them sorts time.
     */
    public function test_record_keys_match_every_vector(): void
    {
        $suite = self::suite('encoding/tid.json');

        $this->assertNotEmpty($suite['vectors']);

        foreach ($suite['vectors'] as $vector) {
            $tid = Tid::parse($vector['tid']);
            $at = $tid->at();

            $this->assertSame(
                $vector['microseconds'],
                ((int) $at->format('U')) * 1_000_000 + ((int) $at->format('u')),
                "record key [{$vector['tid']}]",
            );

            $this->assertSame($vector['clockId'], $tid->clockId());

            $this->assertSame(
                $vector['tid'],
                (string) Tid::fromParts($vector['microseconds'], $vector['clockId']),
            );
        }

        $sorted = $suite['ordering']['unsorted'];
        sort($sorted);

        $this->assertSame($suite['ordering']['sorted'], $sorted);
    }

    /**
     * Not a vector, because it is about a running process rather than a format:
     * two records made in the same microsecond must still get different keys,
     * and they must still sort in the order they were made.
     */
    public function test_keys_stay_unique_and_increasing_under_pressure(): void
    {
        $keys = [];

        for ($i = 0; $i < 5000; $i++) {
            $keys[] = (string) Tid::now();
        }

        $sorted = $keys;
        sort($sorted);

        $this->assertSame($keys, $sorted, 'keys did not increase monotonically');
        $this->assertCount(5000, array_unique($keys), 'keys collided');
    }

    /**
     * Content addressing, against CIDs a live network already assigned.
     */
    public function test_content_addressing_matches_every_vector(): void
    {
        $vectors = self::suite('encoding/cid.json')['vectors'];

        $this->assertNotEmpty($vectors);

        foreach ($vectors as $vector) {
            $this->assertSame(
                $vector['cid'],
                (string) Cid::forRecord($vector['value']),
                "CID for [{$vector['name']}]",
            );

            $this->assertTrue(Cid::parse($vector['cid'])->matches($vector['value']));

            // The property the whole thing rests on: altered content, different
            // name. Otherwise a reference could not mean "as it was".
            $altered = $vector['value'];
            $altered['streetmesh_was_here'] = true;

            $this->assertFalse(Cid::parse($vector['cid'])->matches($altered));
        }
    }

    /**
     * The same arithmetic over bytes that are not a structure.
     *
     * Only the codec byte differs between these and the ones above, which is
     * precisely why they are worth checking separately: every other layer
     * agreeing is what makes a wrong codec so hard to notice. A blob hashed
     * correctly and labelled `dag-cbor` produces a name of exactly the right
     * shape and length that no other implementation would ever agree with.
     */
    public function test_blobs_are_named_under_the_raw_codec(): void
    {
        $vectors = self::suite('encoding/cid.json')['raw'] ?? [];

        $this->assertNotEmpty($vectors);

        foreach ($vectors as $vector) {
            $bytes = base64_decode($vector['bytes'], strict: true);

            if ($bytes === false) {
                $this->fail("[{$vector['name']}] does not carry base64 bytes.");
            }

            $this->assertSame(
                $vector['cid'],
                (string) Cid::forRaw($bytes),
                "CID for [{$vector['name']}]",
            );

            $this->assertTrue(Cid::parse($vector['cid'])->matchesBytes($bytes));
            $this->assertFalse(Cid::parse($vector['cid'])->matchesBytes($bytes.'.'));

            // Round trips through the binary form without losing which codec it
            // was, which is the whole reason the codec is carried rather than
            // assumed.
            $this->assertSame(
                $vector['cid'],
                (string) Cid::fromBytes(Cid::parse($vector['cid'])->toBytes()),
            );
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
