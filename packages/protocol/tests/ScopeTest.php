<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\Scope;

/**
 * ATProtocol's grammar rather than one of ours.
 *
 * The prototype had `records:write`, which was wrong less for being coarse than
 * for being local: a word invented here is a word no other server understands,
 * and a permission nobody else can interpret is two programs agreeing privately
 * rather than federation.
 */
class ScopeTest extends TestCase
{
    private const CHESS = 'com.streetmesh.games.chess';

    public function test_a_bare_collection_permits_everything_on_that_type(): void
    {
        $scope = Scope::parse('repo:'.self::CHESS);

        $this->assertNotNull($scope);

        foreach (Scope::ACTIONS as $action) {
            $this->assertTrue($scope->allows(self::CHESS, $action));
        }

        $this->assertFalse($scope->allows('com.streetmesh.messages.direct', Scope::CREATE));
    }

    /**
     * The one a venue should actually ask for. A resident reading it can tell
     * that whatever gets written cannot afterwards be changed or removed by the
     * venue that wrote it.
     */
    public function test_naming_an_action_permits_only_that_action(): void
    {
        $scope = Scope::parse('repo:'.self::CHESS.'?action=create');

        $this->assertNotNull($scope);
        $this->assertTrue($scope->allows(self::CHESS, Scope::CREATE));
        $this->assertFalse($scope->allows(self::CHESS, Scope::UPDATE));
        $this->assertFalse($scope->allows(self::CHESS, Scope::DELETE));
    }

    public function test_several_actions_can_be_named(): void
    {
        $scope = Scope::parse('repo:'.self::CHESS.'?action=create&action=update');

        $this->assertNotNull($scope);
        $this->assertTrue($scope->allows(self::CHESS, Scope::UPDATE));
        $this->assertFalse($scope->allows(self::CHESS, Scope::DELETE));
    }

    public function test_several_collections_can_be_named(): void
    {
        $scope = Scope::parse('repo?collection=com.a.b&collection=com.c.d');

        $this->assertNotNull($scope);
        $this->assertTrue($scope->allows('com.a.b', Scope::CREATE));
        $this->assertTrue($scope->allows('com.c.d', Scope::CREATE));
        $this->assertFalse($scope->allows('com.e.f', Scope::CREATE));
    }

    public function test_a_wildcard_covers_every_type(): void
    {
        $everything = Scope::parse('repo:*');
        $removalOnly = Scope::parse('repo:*?action=delete');

        $this->assertNotNull($everything);
        $this->assertNotNull($removalOnly);
        $this->assertTrue($everything->allows('anything.at.all', Scope::CREATE));
        $this->assertFalse($removalOnly->allows('anything.at.all', Scope::CREATE));
    }

    /**
     * A token carries several scopes and most of them are about something else
     * entirely. Those are not errors — they simply have no opinion about
     * whether a record may be written.
     */
    public function test_scopes_about_other_things_are_passed_over_rather_than_refused(): void
    {
        $this->assertNull(Scope::parse('atproto'));
        $this->assertNull(Scope::parse('blob:*/*'));
        $this->assertNull(Scope::parse('identity:*'));

        $this->assertTrue(Scope::permits(
            ['atproto', 'blob:*/*', 'repo:'.self::CHESS.'?action=create'],
            self::CHESS,
            Scope::CREATE,
        ));
    }

    public function test_a_token_with_nothing_relevant_permits_nothing(): void
    {
        $this->assertFalse(Scope::permits(['atproto'], self::CHESS, Scope::CREATE));
        $this->assertFalse(Scope::permits([], self::CHESS, Scope::CREATE));
    }

    /**
     * What is written has to read back the same way, or a scope shown to a
     * person on a consent screen would not be the scope their server enforces.
     */
    public function test_a_scope_survives_being_written_out_and_read_back(): void
    {
        foreach ([
            'repo:'.self::CHESS,
            'repo:'.self::CHESS.'?action=create',
            'repo:*',
            'repo:*?action=create&action=delete',
            'repo?collection=com.a.b&collection=com.c.d',
        ] as $original) {
            $this->assertSame($original, (string) Scope::parse($original), $original);
        }
    }

    public function test_it_can_be_built_as_well_as_read(): void
    {
        $this->assertSame(
            'repo:'.self::CHESS.'?action=create',
            (string) Scope::forRepo([self::CHESS], [Scope::CREATE]),
        );

        $this->assertSame('repo:'.self::CHESS, (string) Scope::forRepo([self::CHESS]));
    }

    /**
     * An action nobody defines is not quietly accepted, or a venue could ask
     * for `action=publish` and be granted something that means nothing.
     */
    public function test_an_action_that_does_not_exist_is_dropped(): void
    {
        $scope = Scope::forRepo([self::CHESS], ['publish']);

        $this->assertSame([], $scope->actions);
        $this->assertFalse($scope->allows(self::CHESS, Scope::CREATE));
    }
}
