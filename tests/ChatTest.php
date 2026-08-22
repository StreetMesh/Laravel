<?php

namespace StreetMesh\Server\Tests;

use Livewire\Livewire;
use RuntimeException;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Chat\Chat;
use StreetMesh\Server\Venue\Chat\Message;
use StreetMesh\Server\Venue\Parties\Parties;

/**
 * Talking, in a place.
 *
 * The model is one sentence — post a message to a space and everybody in that
 * space sees it — and a lobby, a table and a party are all spaces. What is
 * actually worth testing is the one place that is not true of: a party is
 * private, and talking into somebody else's would be a real leak.
 */
class ChatTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.parties.enabled', true);
    }

    private function chat(): Chat
    {
        return $this->app->make(Chat::class);
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

    public function test_saying_something_puts_it_in_the_space(): void
    {
        $alice = $this->visitor();

        $this->chat()->say('/lobby', $alice, 'Anybody for a game?');

        $said = Message::recentlyIn('/lobby');

        $this->assertCount(1, $said);
        $this->assertSame('Anybody for a game?', $said->first()->body);
        $this->assertSame($alice->did, $said->first()->did);
    }

    /**
     * A lobby and a table are the same kind of thing, and neither hears the
     * other. This is the whole of what "space" buys.
     */
    public function test_a_space_only_hears_itself(): void
    {
        $alice = $this->visitor();

        $this->chat()->say('/lobby', $alice, 'in the lobby');
        $this->chat()->say('/tables/abc', $alice, 'at the table');

        $this->assertCount(1, Message::recentlyIn('/lobby'));
        $this->assertSame('at the table', Message::recentlyIn('/tables/abc')->first()->body);
    }

    public function test_nothing_is_not_worth_saying(): void
    {
        $this->expectException(RuntimeException::class);

        $this->chat()->say('/lobby', $this->visitor(), '   ');
    }

    public function test_a_novel_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('longer than anybody is going to read');

        $this->chat()->say('/lobby', $this->visitor(), str_repeat('a', Message::LONGEST + 1));
    }

    /**
     * What somebody said outlives their visit.
     *
     * A delegation is deleted the moment a visitor gives their permission back.
     * Hung off it as a foreign key, one person leaving would take both sides of
     * a conversation with them — so who spoke is kept as text.
     */
    public function test_a_conversation_survives_somebody_going_home(): void
    {
        $alice = $this->visitor();
        $bob = $this->visitor('bob');

        $this->chat()->say('/lobby', $alice, 'good game');
        $this->chat()->say('/lobby', $bob, 'you too');

        $bob->delete();

        $said = Message::recentlyIn('/lobby');

        $this->assertCount(2, $said);
        $this->assertSame('you too', $said->last()->body);
        $this->assertSame('bob.home.test', $said->last()->name);
    }

    /**
     * A party is private by construction — the only way in is to be asked — so
     * it is the one space whose membership this can settle on its own.
     */
    public function test_nobody_talks_into_a_party_they_are_not_in(): void
    {
        $alice = $this->visitor();
        $mallory = $this->visitor('mallory');

        $party = $this->app->make(Parties::class)->open($alice);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in that party');

        $this->chat()->say($party->room(), $mallory, 'hello?');
    }

    public function test_a_member_talks_into_their_own_party(): void
    {
        $alice = $this->visitor();
        $party = $this->app->make(Parties::class)->open($alice);

        $this->chat()->say($party->room(), $alice, 'this way');

        $this->assertCount(1, Message::recentlyIn($party->room()));
    }

    public function test_a_party_conversation_is_not_readable_by_outsiders(): void
    {
        $alice = $this->visitor();
        $party = $this->app->make(Parties::class)->open($alice);

        $this->assertTrue($this->chat()->readableBy($party->room(), $alice));
        $this->assertFalse($this->chat()->readableBy($party->room(), $this->visitor('mallory')));
        $this->assertFalse($this->chat()->readableBy($party->room(), null));
    }

    /**
     * Everywhere else is one of this venue's own screens, and who may look at
     * those was decided at the door they came through. A second opinion here
     * would be one more place for the two to disagree.
     */
    public function test_an_ordinary_space_is_readable(): void
    {
        $this->assertTrue($this->chat()->readableBy('/lobby', null));
    }

    /**
     * The newest line is announced to whoever is holding the badge.
     *
     * A conversation carries on whether or not anybody has the panel open —
     * it is hidden rather than unloaded, so it polls either way. This is how
     * the document that *can* tell whether anybody is looking hears that
     * there is something to look at.
     *
     * Keyed on the message, so the element is re-made when a new line lands
     * and left alone when a poll finds nothing.
     */
    public function test_the_newest_line_is_announced_to_the_badge(): void
    {
        $alice = $this->visitor();

        $this->chat()->say('/lobby', $alice, 'anybody about?');

        $newest = Message::recentlyIn('/lobby')->last();

        $panel = Livewire::test('venue::chat', ['space' => '/lobby']);
        $panel->assertOk();

        $said = $panel->html();

        $this->assertStringContainsString('streetmesh.chat.said', $said);
        $this->assertStringContainsString('said-'.$newest->id, $said);
    }

    /**
     * The tail of a conversation, oldest first — which is the order it is read
     * in, and not the order it is cheapest to fetch in.
     */
    public function test_a_long_conversation_shows_its_end(): void
    {
        $alice = $this->visitor();

        foreach (range(1, Message::RECENT + 10) as $number) {
            $this->chat()->say('/lobby', $alice, "message {$number}");
        }

        $said = Message::recentlyIn('/lobby');

        $this->assertCount(Message::RECENT, $said);
        $this->assertSame('message 11', $said->first()->body);
        $this->assertSame('message '.(Message::RECENT + 10), $said->last()->body);
    }
}
