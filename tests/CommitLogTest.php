<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Protocol\Cid;
use StreetMesh\Protocol\Commit;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\RecordTree;
use StreetMesh\Protocol\Tid;
use StreetMesh\Server\Protocol\Records\CommitLog;
use StreetMesh\Server\Protocol\Records\CommitRecord;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\Protocol\Records\RecordStore;

/**
 * The gap this closes: until now a record proved it had not changed, and
 * nothing proved anybody had agreed to it.
 */
class CommitLogTest extends TestCase
{
    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private Ed25519 $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = Ed25519::generate();
    }

    private function log(): CommitLog
    {
        return $this->app->make(CommitLog::class);
    }

    private function store(): RecordStore
    {
        return $this->app->make(RecordStore::class);
    }

    private function write(string $result = 'win'): Record
    {
        return $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => $result]);
    }

    public function test_a_history_of_signed_commits_hangs_together(): void
    {
        $this->write('win');
        $this->log()->commit(self::ALICE, $this->key);

        $this->write('draw');
        $this->log()->commit(self::ALICE, $this->key);

        // Signed by them, each following the last, and describing what is here.
        $this->log()->verify(self::ALICE, $this->key->multikey());

        $this->assertSame(2, CommitRecord::query()->where('did', self::ALICE)->count());
    }

    public function test_the_first_commit_names_nothing_and_the_rest_name_the_last(): void
    {
        $this->write();
        $first = $this->log()->commit(self::ALICE, $this->key);

        $this->write();
        $second = $this->log()->commit(self::ALICE, $this->key);

        $this->assertNull($first->prev);
        $this->assertSame($first->cid, $second->prev);
    }

    /**
     * The failure this exists to catch: a record appears that nobody committed
     * to. The chain is intact and still signed, and it no longer describes what
     * is here.
     */
    public function test_a_record_slipped_in_afterwards_is_detected(): void
    {
        $this->write('win');
        $this->log()->commit(self::ALICE, $this->key);

        $this->log()->verify(self::ALICE, $this->key->multikey());

        // A server adds a game Alice never played.
        $this->write('loss');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the ones its history commits to');

        $this->log()->verify(self::ALICE, $this->key->multikey());
    }

    public function test_a_record_removed_afterwards_is_detected(): void
    {
        $this->write('win');
        $removable = $this->write('draw');
        $this->log()->commit(self::ALICE, $this->key);

        $removable->delete();

        $this->expectException(RuntimeException::class);

        $this->log()->verify(self::ALICE, $this->key->multikey());
    }

    public function test_a_history_signed_by_somebody_else_is_refused(): void
    {
        $this->write();
        $this->log()->commit(self::ALICE, $this->key);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not signed by');

        $this->log()->verify(self::ALICE, Ed25519::generate()->multikey());
    }

    /**
     * Tampering with a stored commit breaks its signature, because everything
     * that matters is inside what was signed — including which commit came
     * before. So the interesting attack is the one where the attacker holds the
     * key, which the server always does.
     */
    public function test_altering_a_stored_commit_breaks_its_signature(): void
    {
        $this->write();
        $this->log()->commit(self::ALICE, $this->key);
        $this->write();
        $second = $this->log()->commit(self::ALICE, $this->key);

        // Substitute a commit signed by somebody else entirely, keeping the
        // stored name — as a database with a stray write would leave it.
        $forged = Commit::of(self::ALICE, Cid::forBytes('a tree'))->signedWith(Ed25519::generate());

        CommitRecord::query()->whereKey($second->id)->toBase()->update([
            'body' => base64_encode($forged->toBytes()),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not signed by');

        $this->log()->verify(self::ALICE, $this->key->multikey());
    }

    /**
     * The real threat, and the one a signature alone cannot answer: the server
     * holds the key, so it can rewrite the past and sign the result perfectly.
     * What it cannot do is make the rewrite fit — the commit after it named the
     * original by its content, and different content is a different name.
     */
    public function test_a_past_rewritten_and_properly_resigned_still_does_not_fit(): void
    {
        $this->write('win');
        $first = $this->log()->commit(self::ALICE, $this->key);
        $this->write('draw');
        $this->log()->commit(self::ALICE, $this->key);

        // The server reissues the first commit covering something else, signs
        // it correctly, and puts it back.
        $rewritten = Commit::of(self::ALICE, Cid::forBytes('a substituted tree'), rev: Tid::parse($first->rev))
            ->signedWith($this->key);

        CommitRecord::query()->whereKey($first->id)->toBase()->update([
            'cid' => (string) $rewritten->cid(),
            'data' => (string) $rewritten->data,
            'body' => base64_encode($rewritten->toBytes()),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has been rewritten');

        $this->log()->verify(self::ALICE, $this->key->multikey());
    }

    public function test_a_commit_cannot_be_edited(): void
    {
        $this->write();
        $commit = $this->log()->commit(self::ALICE, $this->key);

        $this->expectException(RuntimeException::class);

        $commit->update(['data' => 'bafyreisomethingelse']);
    }

    /**
     * Private records are committed to as well. Committing only to the public
     * ones would let a server add or drop a private record with nothing to show
     * for it — and the people most needing that protection are exactly the ones
     * whose records are private.
     */
    public function test_private_records_are_committed_to_as_well(): void
    {
        $this->store()->put(self::ALICE, 'com.streetmesh.messages.direct', ['body' => 'hello']);
        $this->log()->commit(self::ALICE, $this->key);

        $this->log()->verify(self::ALICE, $this->key->multikey());

        $this->store()->put(self::ALICE, 'com.streetmesh.messages.direct', ['body' => 'not from alice']);

        $this->expectException(RuntimeException::class);

        $this->log()->verify(self::ALICE, $this->key->multikey());
    }

    public function test_one_residents_history_is_untouched_by_another(): void
    {
        $this->write();
        $this->log()->commit(self::ALICE, $this->key);

        $bobsKey = Ed25519::generate();
        $this->store()->put('did:plc:bob', 'com.streetmesh.games.chess', ['result' => 'win']);
        $this->log()->commit('did:plc:bob', $bobsKey);

        $this->log()->verify(self::ALICE, $this->key->multikey());
        $this->log()->verify('did:plc:bob', $bobsKey->multikey());

        $this->assertNull($this->log()->head('did:plc:bob')->prev);
    }

    /**
     * A commit is only worth what its root is worth. Bound to a tree a stranger
     * cannot recompute, a chain is honest history nobody else can read — which
     * is a thing to notice deliberately rather than to discover while trying to
     * federate.
     */
    public function test_the_bound_tree_is_one_other_software_can_read(): void
    {
        $this->assertTrue(
            $this->app->make(RecordTree::class)->isInteroperable(),
            'commits are being signed over a root no other implementation can compute',
        );
    }
}
