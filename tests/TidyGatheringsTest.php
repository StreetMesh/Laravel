<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Gatherings\Gatherings;

/**
 * Clearing away tables nobody came to.
 *
 * The whole risk here is deleting something somebody is using, so most of these
 * are about what it declines to touch.
 */
final class TidyGatheringsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('streetmesh.venue.hub', 'wss://hub.test');
    }

    public function test_it_clears_a_table_nobody_came_to(): void
    {
        $waiting = $this->table(minutesAgo: 20, seats: 1);

        $this->hubSays('{}');

        $this->artisan('gatherings:tidy')->assertSuccessful();

        $this->assertNull(Gathering::find($waiting->id));
    }

    public function test_it_leaves_a_table_that_has_not_waited_long(): void
    {
        $fresh = $this->table(minutesAgo: 2, seats: 1);

        $this->hubSays('{}');

        $this->artisan('gatherings:tidy')->assertSuccessful();

        $this->assertNotNull(Gathering::find($fresh->id));
    }

    /**
     * Two people met. Whether or not a move was played, that is a game, and a
     * game is somebody's to conclude rather than this command's to delete.
     */
    public function test_it_leaves_a_table_two_people_sat_at(): void
    {
        $game = $this->table(minutesAgo: 60, seats: 2);

        $this->hubSays('{}');

        $this->artisan('gatherings:tidy')->assertSuccessful();

        $this->assertNotNull(Gathering::find($game->id));
    }

    /**
     * Waiting is not abandoning. Somebody with the page open is at the table,
     * however long they have been there.
     */
    public function test_it_leaves_a_table_somebody_is_sitting_at(): void
    {
        $waiting = $this->table(minutesAgo: 60, seats: 1);

        $this->hubSays(json_encode([
            $waiting->room() => [['name' => 'alice.home.test', 'seat' => 'white']],
        ]));

        $this->artisan('gatherings:tidy')->assertSuccessful();

        $this->assertNotNull(Gathering::find($waiting->id));
    }

    /**
     * The one that matters most. Whether anybody is at a table is something
     * only the hub knows, and a hub that cannot be reached answers "nobody is
     * anywhere" — which, acted on, clears every table on the worst minute
     * there is.
     */
    public function test_it_clears_nothing_when_the_hub_is_not_answering(): void
    {
        $waiting = $this->table(minutesAgo: 60, seats: 1);

        // The default fake answers null to everything, as an unreachable hub does.
        $this->artisan('gatherings:tidy')
            ->expectsOutputToContain('not answering')
            ->assertSuccessful();

        $this->assertNotNull(Gathering::find($waiting->id));
    }

    public function test_pretending_takes_nothing(): void
    {
        $waiting = $this->table(minutesAgo: 60, seats: 1);

        $this->hubSays('{}');

        $this->artisan('gatherings:tidy', ['--pretend' => true])->assertSuccessful();

        $this->assertNotNull(Gathering::find($waiting->id));
    }

    /**
     * A hub that answers, and says what it was told to say.
     */
    private function hubSays(string $present): void
    {
        $this->app->instance(Network::class, new class($present) implements Network
        {
            public function __construct(private readonly string $present) {}

            public function get(string $url): string
            {
                return str_contains($url, '/present') ? $this->present : '{"build":"test"}';
            }

            /**
             * @return array<int, string>
             */
            public function txt(string $name): array
            {
                return [];
            }

            /**
             * @param  array<string, string>  $headers
             * @return array{status: int, body: string}
             */
            public function post(string $url, string $body, array $headers = []): array
            {
                return ['status' => 200, 'body' => ''];
            }
        });
    }

    private function table(int $minutesAgo, int $seats): Gathering
    {
        $gathering = $this->app->make(Gatherings::class)->open('com.example.pinball');

        for ($i = 0; $i < $seats; $i++) {
            $this->app->make(Gatherings::class)->seat($gathering, Delegation::create([
                'did' => "did:web:player{$i}.home.test",
                'handle' => "player{$i}.home.test",
                'issuer' => 'https://home.test',
                'dpop_key' => Delegation::store(P256::generate()),
                'access_token' => 'a-live-token',
                'scope' => 'atproto',
                'expires_at' => now()->addMinutes(15),
            ]), $i === 0 ? 'white' : 'black');
        }

        $gathering->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $gathering->fresh();
    }
}
