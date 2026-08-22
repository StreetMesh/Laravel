<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;
use StreetMesh\Server\Venue\Visitors;

/**
 * Carrying the notes that let two browsers in a party talk directly.
 *
 * A handshake and then nothing: an offer, an answer, a few addresses, after
 * which the audio and video go straight between the two browsers and this
 * server never sees a frame.
 *
 * What is worth protecting is who may reach the box. A party is private by
 * construction, so somebody outside one has no business either reading what is
 * waiting in it or leaving anything there — and the refusal is deliberately the
 * same either way, because telling "no such party" apart from "not yours" would
 * say whether a given party exists to somebody with no business knowing.
 */
class SignalTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.parties.enabled', true);
    }

    private function visitor(string $who = 'alice'): Delegation
    {
        return Delegation::create([
            'did' => 'did:web:'.$who.'.home.test',
            'handle' => $who.'.home.test',
            'issuer' => 'https://home.test',
            'dpop_key' => Delegation::store(P256::generate()),
            'access_token' => 'a-live-token',
            'scope' => 'atproto',
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function parties(): Parties
    {
        return $this->app->make(Parties::class);
    }

    /** @return array{0: Party, 1: Delegation, 2: Delegation} */
    private function partyOfTwo(): array
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $party = $this->parties()->open($alice);
        $this->parties()->accept($this->parties()->invite($party, $alice, (string) $bob->did), $bob);

        return [$party, $alice, $bob];
    }

    private function as(Delegation $who): self
    {
        $this->withSession([Visitors::SESSION_KEY => $who->id]);

        return $this;
    }

    public function test_a_note_left_for_somebody_is_waiting_for_them(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->as($alice)->postJson(route('venue.parties.signal', $party->key), [
            'from' => 'alice-tab',
            'to' => 'bob-tab',
            'data' => ['description' => ['type' => 'offer']],
        ])->assertOk();

        $waiting = $this->as($bob)
            ->getJson(route('venue.parties.signals', $party->key).'?as=bob-tab')
            ->assertOk()
            ->json('signals');

        $this->assertCount(1, $waiting);
        $this->assertSame('alice-tab', $waiting[0]['from']);
        $this->assertSame('offer', $waiting[0]['data']['description']['type']);
    }

    /**
     * A session description arrives byte for byte.
     *
     * An SDP is a line-oriented document whose every line ends in CRLF,
     * including the last. Laravel trims request input as a kindness to HTML
     * forms, and applied here that takes the terminator off the final line —
     * the far side then refuses the whole thing with "Invalid SDP line", and
     * every ICE candidate for it fails after with "the remote description was
     * null". Two browsers sat looking at each other and nothing said why.
     */
    public function test_a_session_description_is_not_tidied_on_the_way_through(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $sdp = "v=0\r\no=- 4611731400430051336 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";

        $this->as($alice)->postJson(route('venue.parties.signal', $party->key), [
            'from' => 'alice-tab',
            'to' => 'bob-tab',
            'data' => ['description' => ['type' => 'offer', 'sdp' => $sdp]],
        ])->assertOk();

        $waiting = $this->as($bob)
            ->getJson(route('venue.parties.signals', $party->key).'?as=bob-tab')
            ->json('signals');

        $this->assertSame($sdp, $waiting[0]['data']['description']['sdp']);
        $this->assertStringEndsWith("\r\n", $waiting[0]['data']['description']['sdp']);
    }

    /**
     * Addressed to a connection rather than to a person.
     *
     * The same human with a laptop and a phone is two peers and needs two
     * handshakes, and an offer collected by the wrong browser is one answered
     * by a connection that never made it.
     */
    public function test_a_note_is_only_waiting_for_the_connection_it_names(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->as($alice)->postJson(route('venue.parties.signal', $party->key), [
            'from' => 'alice-tab',
            'to' => 'bob-laptop',
            'data' => ['candidate' => 'one'],
        ])->assertOk();

        $this->assertSame([], $this->as($bob)
            ->getJson(route('venue.parties.signals', $party->key).'?as=bob-phone')
            ->json('signals'));
    }

    public function test_collecting_empties_the_box(): void
    {
        [$party, $alice, $bob] = $this->partyOfTwo();

        $this->as($alice)->postJson(route('venue.parties.signal', $party->key), [
            'from' => 'alice-tab',
            'to' => 'bob-tab',
            'data' => ['candidate' => 'one'],
        ]);

        $this->as($bob)->getJson(route('venue.parties.signals', $party->key).'?as=bob-tab');

        $this->assertSame([], $this->as($bob)
            ->getJson(route('venue.parties.signals', $party->key).'?as=bob-tab')
            ->json('signals'));
    }

    public function test_somebody_outside_the_party_cannot_leave_anything(): void
    {
        [$party] = $this->partyOfTwo();

        $this->as($this->visitor('mallory'))
            ->postJson(route('venue.parties.signal', $party->key), [
                'from' => 'mallory-tab',
                'to' => 'bob-tab',
                'data' => ['description' => ['type' => 'offer']],
            ])
            ->assertNotFound();
    }

    public function test_somebody_outside_the_party_cannot_read_it(): void
    {
        [$party] = $this->partyOfTwo();

        $this->as($this->visitor('mallory'))
            ->getJson(route('venue.parties.signals', $party->key).'?as=bob-tab')
            ->assertNotFound();
    }

    /**
     * A disbanded party carries nothing, so a note left against its name has
     * nowhere to go — and neither does an old invitation's idea of it.
     */
    public function test_a_party_that_broke_up_carries_nothing(): void
    {
        [$party, $alice] = $this->partyOfTwo();

        $this->parties()->disband($party);

        $this->as($alice)
            ->getJson(route('venue.parties.signals', $party->key).'?as=alice-tab')
            ->assertNotFound();
    }

    /**
     * What a browser needs before it can start looking for anybody.
     *
     * The party's name, so it knows which box its notes go in, and how to get
     * through its own router. Nothing signed, because there is nothing to join:
     * a party is a space where notes are left and a conversation is kept, and
     * being allowed at one is a question the venue answers here in words.
     */
    public function test_a_member_is_given_what_it_takes_to_start_looking(): void
    {
        [$party, $alice] = $this->partyOfTwo();

        $admitted = $this->as($alice)
            ->postJson(route('venue.parties.ticket', $party->key))
            ->assertOk()
            ->json();

        $this->assertSame($party->room(), $admitted['room']);

        $this->assertArrayNotHasKey('ticket', $admitted, 'nothing is joined, so nothing is signed');
        $this->assertArrayNotHasKey('type', $admitted);
        $this->assertArrayNotHasKey('hub', $admitted, 'a party needs no hub at all now');

        /*
         * How a browser gets through its own router. A property of
         * peer-to-peer rather than an operator's decision, which is why it is
         * sent rather than configured.
         */
        $this->assertNotEmpty($admitted['ice']);
    }
}
