<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use StreetMesh\Protocol\BlobScope;
use StreetMesh\Protocol\Scope;

/**
 * Permission to put bytes in somebody's repository.
 *
 * The half of the grammar that decides what may be *kept*, as opposed to what
 * kind of record may be *written*. Until this existed there was no upload
 * endpoint, because a permission the endpoint cannot enforce and the consent
 * screen cannot describe is not a permission at all.
 */
class BlobScopeTest extends TestCase
{
    private const MODEL = 'model/gltf-binary';

    public function test_a_bare_type_permits_that_type_and_no_other(): void
    {
        $scope = BlobScope::parse('blob:'.self::MODEL);

        $this->assertNotNull($scope);
        $this->assertTrue($scope->accepts(self::MODEL));
        $this->assertFalse($scope->accepts('image/png'));
    }

    public function test_a_family_permits_everything_under_it(): void
    {
        $scope = BlobScope::parse('blob:image/*');

        $this->assertNotNull($scope);
        $this->assertTrue($scope->accepts('image/png'));
        $this->assertTrue($scope->accepts('image/jpeg'));
        $this->assertFalse($scope->accepts(self::MODEL));
    }

    public function test_the_wildcard_permits_anything(): void
    {
        $scope = BlobScope::parse('blob:'.BlobScope::ANY);

        $this->assertNotNull($scope);
        $this->assertTrue($scope->accepts('image/png'));
        $this->assertTrue($scope->accepts(self::MODEL));
    }

    /**
     * Repeated keys mean all of them.
     *
     * The trap `parse_str` walks into: it keeps only the last unless the name
     * ends in `[]`, which would quietly narrow this to models alone.
     */
    public function test_several_types_at_once_are_all_kept(): void
    {
        $scope = BlobScope::parse('blob?accept=image/png&accept='.self::MODEL);

        $this->assertNotNull($scope);
        $this->assertTrue($scope->accepts('image/png'));
        $this->assertTrue($scope->accepts(self::MODEL));
        $this->assertFalse($scope->accepts('image/jpeg'));
    }

    /**
     * The emptiest permission must not be the widest one.
     *
     * Reading a blob scope that names nothing as "anything" is the single
     * mistake in a permission grammar that no amount of care elsewhere
     * recovers from.
     */
    public function test_a_scope_naming_nothing_is_not_a_scope(): void
    {
        $this->assertNull(BlobScope::parse('blob'));
        $this->assertNull(BlobScope::parse('blob:'));
        $this->assertNull(BlobScope::parse('blob?'));
        $this->assertNull(BlobScope::parse('blob?accept'));
    }

    /**
     * Each half of the grammar declines to answer the other's question.
     *
     * `Scope` returns null for a blob scope on the stated grounds that it does
     * not decide whether a record may be written. This is the other half of
     * that sentence, and the two must not start answering for each other.
     */
    public function test_the_two_kinds_of_scope_do_not_read_each_other(): void
    {
        $this->assertNull(BlobScope::parse('repo:com.streetmesh.actor.avatar'));
        $this->assertNull(BlobScope::parse('identity:*'));
        $this->assertNull(BlobScope::parse('atproto'));

        $this->assertNull(Scope::parse('blob:'.self::MODEL));
    }

    /**
     * A token carries several, and only one of them has to say yes.
     */
    public function test_one_scope_among_several_is_enough(): void
    {
        $granted = ['atproto', 'repo:com.streetmesh.actor.avatar?action=create', 'blob:image/png'];

        $this->assertTrue(BlobScope::permits($granted, 'image/png'));
        $this->assertFalse(BlobScope::permits($granted, self::MODEL));
        $this->assertFalse(BlobScope::permits(['atproto'], 'image/png'));
    }

    /**
     * Media types are case-insensitive, and what follows a semicolon is not
     * part of the type. A permission that missed a PNG because it arrived
     * announced with a charset would be refusing on a technicality nobody
     * wrote down.
     */
    public function test_a_type_is_matched_as_a_type_rather_than_as_a_string(): void
    {
        $scope = BlobScope::parse('blob:IMAGE/PNG');

        $this->assertNotNull($scope);
        $this->assertTrue($scope->accepts('image/png'));
        $this->assertTrue($scope->accepts('image/png; charset=utf-8'));
    }

    public function test_something_that_is_not_a_type_is_never_accepted(): void
    {
        $scope = BlobScope::parse('blob:'.BlobScope::ANY);

        $this->assertNotNull($scope);
        $this->assertFalse($scope->accepts('png'));
        $this->assertFalse($scope->accepts(''));
        $this->assertFalse($scope->accepts('/png'));
    }

    /**
     * What a resident is shown is what the venue asked for.
     */
    public function test_a_scope_survives_being_written_out_and_read_back(): void
    {
        foreach (['blob:'.self::MODEL, 'blob?accept=image/png&accept='.self::MODEL] as $written) {
            $this->assertSame($written, (string) BlobScope::parse($written));
        }

        $this->assertSame('blob:image/png', (string) BlobScope::forTypes(['image/png', 'image/png']));
    }
}
