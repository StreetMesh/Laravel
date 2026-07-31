<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\Commit;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\FlatTree;
use StreetMesh\Protocol\Tid;

/**
 * What a commit does, and — as importantly — what it does not.
 */
class CommitTest extends TestCase
{
    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private function tree(): FlatTree
    {
        return new FlatTree;
    }

    public function test_a_commit_is_signed_and_checks_out(): void
    {
        $key = Ed25519::generate();

        $root = $this->tree()->root(['com.streetmesh.games.chess/3mqcp5qjdfs26' => 'bafyreiaaa']);
        $commit = Commit::of(self::ALICE, (string) $root)->signedWith($key);

        $this->assertTrue($commit->verify($key->publicKey()));
        $this->assertFalse($commit->verify(Ed25519::generate()->publicKey()));
    }

    public function test_an_unsigned_commit_is_worth_nothing(): void
    {
        $commit = Commit::of(self::ALICE, 'bafyreiaaa');

        $this->assertFalse($commit->verify(Ed25519::generate()->publicKey()));
    }

    /**
     * The root must depend on which records exist, not on the order they were
     * written — or two servers holding the same records would disagree about
     * what they are holding.
     */
    public function test_the_root_depends_on_the_set_and_not_on_the_order(): void
    {
        $one = $this->tree()->root(['a/1' => 'bafyreia', 'b/2' => 'bafyreib']);
        $other = $this->tree()->root(['b/2' => 'bafyreib', 'a/1' => 'bafyreia']);

        $this->assertSame((string) $one, (string) $other);
    }

    public function test_adding_removing_or_altering_a_record_changes_the_root(): void
    {
        $original = ['a/1' => 'bafyreia', 'b/2' => 'bafyreib'];
        $root = (string) $this->tree()->root($original);

        $this->assertNotSame($root, (string) $this->tree()->root([...$original, 'c/3' => 'bafyreic']));
        $this->assertNotSame($root, (string) $this->tree()->root(['a/1' => 'bafyreia']));
        $this->assertNotSame($root, (string) $this->tree()->root([...$original, 'b/2' => 'bafyreix']));
    }

    /**
     * The property that makes history a chain rather than a state.
     */
    public function test_each_commit_names_the_one_before_it(): void
    {
        $key = Ed25519::generate();

        $first = Commit::of(self::ALICE, 'bafyreia')->signedWith($key);
        $second = Commit::of(self::ALICE, 'bafyreib', prev: (string) $first->cid())->signedWith($key);

        $this->assertTrue($second->follows($first));
    }

    /**
     * A server that rewrites the past produces a link that does not fit, and
     * anybody holding the earlier one can say so. This is the whole of what a
     * commit chain buys — not prevention, but a fork somebody can point at.
     */
    public function test_a_rewritten_history_does_not_fit_together(): void
    {
        $key = Ed25519::generate();

        $first = Commit::of(self::ALICE, 'bafyreia')->signedWith($key);
        $second = Commit::of(self::ALICE, 'bafyreib', prev: (string) $first->cid())->signedWith($key);

        /*
         * The server goes back and reissues the first commit covering different
         * records, at the same moment, re-signing it perfectly well — it holds
         * the key, after all. Substituting the past is entirely within its
         * power, and no signature scheme can take that away.
         */
        $rewritten = Commit::of(self::ALICE, 'bafyreitampered', rev: Tid::parse($first->rev))
            ->signedWith($key);

        $this->assertTrue(
            $rewritten->verify($key->publicKey()),
            'a held key signs whatever it is asked to, which is why a signature alone is not enough',
        );

        /*
         * What it cannot do is make the substitute fit. The second commit named
         * the original by its content, and different content is a different
         * name — so anybody holding the original can show the two histories
         * disagree. Detection rather than prevention, which is the honest
         * guarantee.
         */
        $this->assertNotSame((string) $first->cid(), (string) $rewritten->cid());
        $this->assertFalse($second->follows($rewritten), 'the chain must not accept a substituted past');
        $this->assertTrue($second->follows($first), 'and must still accept the real one');
    }

    public function test_a_commit_survives_being_written_down_and_read_back(): void
    {
        $key = Ed25519::generate();

        $commit = Commit::of(self::ALICE, 'bafyreia', prev: 'bafyreiprev')->signedWith($key);
        $restored = Commit::fromArray($commit->toArray());

        $this->assertSame((string) $commit->cid(), (string) $restored->cid());
        $this->assertTrue($restored->verify($key->publicKey()));
    }

    /**
     * Named rather than assumed, so that nobody discovers it at the point of
     * trying to federate.
     */
    public function test_the_current_tree_is_honest_about_not_being_interoperable(): void
    {
        $this->assertFalse($this->tree()->isInteroperable());
    }
}
