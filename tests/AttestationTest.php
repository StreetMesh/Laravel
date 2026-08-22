<?php

namespace StreetMesh\Server\Tests;

use DateTimeImmutable;
use RuntimeException;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\Multikey;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Server\Protocol\Attestations\Attestations;
use StreetMesh\Server\Protocol\Identity\DidResolver;
use StreetMesh\Server\Protocol\Records\RecordStore;

/**
 * A venue says what happened; a domicile checks it and keeps it.
 *
 * The whole claim of the project, end to end, with nothing trusted between the
 * two servers except mathematics and a public directory.
 */
class AttestationTest extends TestCase
{
    private const VENUE = 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa';

    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = new FakeNetwork;
        $this->app->instance(Network::class, $this->network);
        $this->app->forgetInstance(PlcDirectory::class);
        $this->app->forgetInstance(DidResolver::class);
    }

    private function attestations(): Attestations
    {
        return new Attestations(new DidResolver(
            $this->network,
            new PlcDirectory($this->network),
        ));
    }

    /**
     * A log shaped like the directory's, with a key change in the middle.
     *
     * @param  array<int, array{key: Ed25519, at: string}>  $keys
     */
    private function publishAuditLog(string $did, array $keys): void
    {
        $log = array_map(fn (array $entry): array => [
            'did' => $did,
            'createdAt' => $entry['at'],
            'nullified' => false,
            'operation' => [
                'type' => 'plc_operation',
                'verificationMethods' => [
                    'streetmesh' => 'did:key:'.Multikey::fromBase64($entry['key']->publicKey()),
                ],
            ],
        ], $keys);

        $this->network->serve(PlcDirectory::DEFAULT.'/'.$did.'/log/audit', $log);
    }

    public function test_a_venues_statement_verifies_at_a_domicile_that_has_never_met_it(): void
    {
        $venueKey = Ed25519::generate();
        $this->publishAuditLog(self::VENUE, [['key' => $venueKey, 'at' => '2026-01-01T00:00:00.000Z']]);

        $compact = $this->attestations()->issue(
            ['type' => 'com.streetmesh.games.chess', 'subject' => self::ALICE, 'result' => 'win'],
            $venueKey,
            self::VENUE.'#streetmesh',
        );

        // No registration, no token, no prior relationship. Just the document
        // and a public directory.
        $attestation = $this->attestations()->verify($compact, new DateTimeImmutable('2026-06-01T00:00:00Z'));

        $this->assertSame(self::VENUE, $attestation->issuer);
        $this->assertSame('win', $attestation->claim('result'));
        $this->assertTrue($attestation->checkedAgainstHistory());
    }

    /**
     * The reason key history exists at all.
     */
    public function test_a_statement_still_verifies_after_the_venue_rotates_its_key(): void
    {
        $oldKey = Ed25519::generate();
        $newKey = Ed25519::generate();

        $this->publishAuditLog(self::VENUE, [
            ['key' => $oldKey, 'at' => '2026-01-01T00:00:00.000Z'],
            ['key' => $newKey, 'at' => '2026-06-01T00:00:00.000Z'],
        ]);

        $signedInMarch = $this->attestations()->issue(
            ['result' => 'win'],
            $oldKey,
            self::VENUE.'#streetmesh',
        );

        // Checked as of when it arrived, in March, against the key that was
        // current then — not against the one published today.
        $attestation = $this->attestations()->verify($signedInMarch, new DateTimeImmutable('2026-03-01T00:00:00Z'));

        $this->assertSame('win', $attestation->claim('result'));
        $this->assertTrue($attestation->checkedAgainstHistory());

        // And the same document checked as of today fails, correctly: today's
        // key did not sign it. This is the failure the history prevents.
        $this->expectException(RuntimeException::class);

        $this->attestations()->verify($signedInMarch, new DateTimeImmutable('2026-09-01T00:00:00Z'));
    }

    public function test_a_forged_statement_does_not_verify(): void
    {
        $venueKey = Ed25519::generate();
        $impostor = Ed25519::generate();

        $this->publishAuditLog(self::VENUE, [['key' => $venueKey, 'at' => '2026-01-01T00:00:00.000Z']]);

        $forged = $this->attestations()->issue(['result' => 'win'], $impostor, self::VENUE.'#streetmesh');

        $this->expectException(RuntimeException::class);

        $this->attestations()->verify($forged, new DateTimeImmutable('2026-06-01T00:00:00Z'));
    }

    /**
     * did:web publishes a document and no history, so a verifier gets an answer
     * that is correct now and unknowable for anything older. It must be able to
     * tell the difference.
     */
    public function test_an_issuer_without_key_history_says_so(): void
    {
        $key = Ed25519::generate();
        $did = 'did:web:games.example';

        $this->network->serve('https://games.example/.well-known/did.json', [
            'id' => $did,
            'verificationMethod' => [[
                'id' => $did.'#streetmesh',
                'type' => 'Multikey',
                'controller' => $did,
                'publicKeyMultibase' => Multikey::fromBase64($key->publicKey()),
            ]],
        ]);

        $compact = $this->attestations()->issue(['result' => 'draw'], $key, $did.'#streetmesh');

        $attestation = $this->attestations()->verify($compact, new DateTimeImmutable('2026-06-01T00:00:00Z'));

        $this->assertSame('draw', $attestation->claim('result'));
        $this->assertFalse(
            $attestation->checkedAgainstHistory(),
            'a did:web issuer cannot prove which key was current earlier, and the verifier must not imply it could',
        );
    }

    /**
     * A document reached at the right URL that names somebody else is not that
     * identity's document, however it was reached.
     */
    public function test_a_document_that_claims_another_identity_is_refused(): void
    {
        $key = Ed25519::generate();

        $this->network->serve('https://games.example/.well-known/did.json', [
            'id' => 'did:web:somebody.else',
            'verificationMethod' => [[
                'id' => 'did:web:games.example#streetmesh',
                'publicKeyMultibase' => Multikey::fromBase64($key->publicKey()),
            ]],
        ]);

        $compact = $this->attestations()->issue(['result' => 'win'], $key, 'did:web:games.example#streetmesh');

        $this->expectException(RuntimeException::class);

        $this->attestations()->verify($compact);
    }

    public function test_a_verified_statement_becomes_a_record_the_player_holds(): void
    {
        $venueKey = Ed25519::generate();
        $this->publishAuditLog(self::VENUE, [['key' => $venueKey, 'at' => '2026-01-01T00:00:00.000Z']]);

        $compact = $this->attestations()->issue(
            ['type' => 'com.streetmesh.games.chess', 'subject' => self::ALICE, 'result' => 'win', 'pgn' => ''],
            $venueKey,
            self::VENUE.'#streetmesh',
        );

        $attestation = $this->attestations()->verify($compact, new DateTimeImmutable('2026-06-01T00:00:00Z'));

        $record = $this->app->make(RecordStore::class)->put(
            did: self::ALICE,
            collection: 'com.streetmesh.games.chess',
            value: $attestation->toRecord(),
        );

        // Alice's record, at her address, holding the venue's signed statement
        // inside it — hers to keep, and checkable without her server.
        $this->assertSame(self::ALICE, $record->did);
        $this->assertSame('win', $record->value['result']);
        $this->assertSame($compact, $record->value['attestation']);

        // The blank field survives, because a JWS is signed as the bytes it
        // travels as and nothing between here and there can reach inside it.
        $this->assertSame('', $record->value['pgn']);

        // And it re-verifies straight out of storage, which is the test that
        // the record is worth anything at all.
        $again = $this->attestations()->verify(
            $record->value['attestation'],
            new DateTimeImmutable('2026-06-01T00:00:00Z'),
        );

        $this->assertSame('win', $again->claim('result'));
    }

    public function test_everything_is_resolvable_from_the_container(): void
    {
        // A package whose pieces have to be wired by hand is a package that
        // gets wired wrongly.
        $this->assertInstanceOf(Attestations::class, $this->app->make(Attestations::class));
        $this->assertInstanceOf(DidResolver::class, $this->app->make(DidResolver::class));
        $this->assertInstanceOf(RecordStore::class, $this->app->make(RecordStore::class));
    }
}
