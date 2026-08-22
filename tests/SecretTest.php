<?php

namespace StreetMesh\Server\Tests;

use RuntimeException;
use StreetMesh\Server\Venue\Realtime\Secrets;

/**
 * The one thing a hub says to a venue with.
 *
 * Everything else between them is one-way and needs no secret: a ticket is
 * signed here and merely verified there, and a result is asked for rather than
 * announced. This is the direction that cannot work that way — a hub telling a
 * venue something has to be a hub the venue can recognise, and a hub holds no
 * key of its own.
 */
class SecretTest extends TestCase
{
    private function secrets(): Secrets
    {
        return $this->app->make(Secrets::class);
    }

    public function test_what_the_hub_offers_is_recognised(): void
    {
        config()->set('streetmesh.venue.secret', 'the-one-we-share');

        $this->assertTrue($this->secrets()->accepts('the-one-we-share'));
        $this->assertFalse($this->secrets()->accepts('something-else'));
    }

    public function test_nothing_at_all_is_not_a_secret(): void
    {
        config()->set('streetmesh.venue.secret', 'the-one-we-share');

        $this->assertFalse($this->secrets()->accepts(null));
        $this->assertFalse($this->secrets()->accepts(''));
    }

    /**
     * Rotating one shared value in place means a moment where one side has
     * changed it and the other has not, and that moment is an outage. Both are
     * accepted until the old one is taken off the list, so the two sides can be
     * deployed in either order and at whatever interval suits.
     */
    public function test_both_the_old_and_the_new_are_accepted_while_rotating(): void
    {
        config()->set('streetmesh.venue.secret', 'the-new-one,the-old-one');

        $this->assertTrue($this->secrets()->accepts('the-new-one'));
        $this->assertTrue($this->secrets()->accepts('the-old-one'));
        $this->assertFalse($this->secrets()->accepts('never-a-secret'));

        // The newest is the one to hand out, so a rotation finishes rather than
        // settling into two live secrets forever.
        $this->assertSame('the-new-one', $this->secrets()->current());
    }

    public function test_a_list_written_with_spaces_is_still_a_list(): void
    {
        config()->set('streetmesh.venue.secret', ' the-new-one , the-old-one ');

        $this->assertTrue($this->secrets()->accepts('the-old-one'));
        $this->assertSame('the-new-one', $this->secrets()->current());
    }

    /**
     * A venue with no secret cannot tell a hub from anybody else. Saying so is
     * the only honest answer — the failure without it is silence, where results
     * never arrive and nothing looks wrong.
     */
    public function test_a_venue_with_no_secret_says_so(): void
    {
        config()->set('streetmesh.venue.secret', null);

        $this->assertFalse($this->secrets()->configured());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STREETMESH_REALTIME_SECRET/');

        $this->secrets()->all();
    }

    public function test_an_empty_secret_is_no_secret(): void
    {
        config()->set('streetmesh.venue.secret', '   ,  ');

        $this->assertFalse($this->secrets()->configured());
        $this->assertFalse($this->secrets()->accepts(''));
    }
}
