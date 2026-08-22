<?php

namespace StreetMesh\Server\Tests;

use PHPUnit\Framework\TestCase as Plain;
use StreetMesh\Server\Venue\Experiences\Audience;
use StreetMesh\Server\Venue\Experiences\Experience;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Gatherings\Gathering;
use StreetMesh\Server\Venue\Hub\Build;

/**
 * The hub a server writes out for itself.
 *
 * What is being checked is mostly that the artifact is self-contained: a room
 * arrives written against `@streetmesh/hub`, and inside the artifact there are
 * no packages for that to mean anything.
 */
final class BuildHubTest extends Plain
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir().'/hub-build-test-'.bin2hex(random_bytes(6));

        mkdir($this->work.'/hub/src', 0755, true);
        file_put_contents($this->work.'/hub/src/mod.ts', "export const door = true\n");
        file_put_contents($this->work.'/hub/package.json', '{"dependencies":{"colyseus":"^0.16.0"}}');

        mkdir($this->work.'/pinball/src', 0755, true);
        file_put_contents(
            $this->work.'/pinball/src/room.ts',
            "import { door } from '@streetmesh/hub'\nexport default { name: 'com.example.pinball', room: class {} }\n",
        );
        file_put_contents($this->work.'/pinball/package.json', '{"dependencies":{"physics":"^2.0.0"}}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->work));
    }

    /**
     * @return array{rooms: array<int, string>, fingerprint: string}
     */
    private function build(Experience ...$offered): array
    {
        $experiences = new Experiences;

        foreach ($offered as $experience) {
            $experiences->register($experience);
        }

        return (new Build($experiences, $this->work.'/hub', $this->work.'/out'))->run();
    }

    public function test_it_writes_a_hub_that_can_be_started(): void
    {
        $built = $this->build($this->pinball());

        $this->assertSame(['com.example.pinball'], $built['rooms']);

        foreach (['index.ts', 'app.config.ts', 'package.json', 'tsconfig.json', 'ecosystem.config.cjs'] as $file) {
            $this->assertFileExists($this->work.'/out/'.$file);
        }

        $this->assertFileExists($this->work.'/out/hub/mod.ts');
        $this->assertFileExists($this->work.'/out/rooms/com.example.pinball/room.ts');
    }

    /**
     * The reason the artifact is flat at all. A room names the hub as a package
     * because that is what it is while it lives on its own; inside the artifact
     * nothing would resolve that name.
     */
    public function test_a_rooms_import_of_the_hub_is_repointed_at_the_copy_beside_it(): void
    {
        $this->build($this->pinball());

        $room = (string) file_get_contents($this->work.'/out/rooms/com.example.pinball/room.ts');

        $this->assertStringNotContainsString('@streetmesh/hub', $room);
        $this->assertStringContainsString("from '../../hub/mod.ts'", $room);
    }

    /**
     * The venue does not know what a room needs, and should not have to. A
     * chess room needs a chess engine; this one needs physics.
     */
    public function test_it_collects_what_each_room_brought_with_it(): void
    {
        $this->build($this->pinball());

        $package = json_decode((string) file_get_contents($this->work.'/out/package.json'), true);

        $this->assertArrayHasKey('physics', $package['dependencies']);
        $this->assertArrayHasKey('colyseus', $package['dependencies']);
    }

    /**
     * What the deploy leans on. A hub restart ends every game in progress, so a
     * deploy has to be able to tell that nothing changed — and two builds of
     * the same source saying different things would mean redeploying always.
     */
    public function test_the_same_source_fingerprints_the_same_twice(): void
    {
        $this->assertSame(
            $this->build($this->pinball())['fingerprint'],
            $this->build($this->pinball())['fingerprint'],
        );
    }

    public function test_a_changed_room_changes_the_fingerprint(): void
    {
        $before = $this->build($this->pinball())['fingerprint'];

        file_put_contents($this->work.'/pinball/src/room.ts', "export default { name: 'com.example.pinball', room: class {} }\n");

        $this->assertNotSame($before, $this->build($this->pinball())['fingerprint']);
    }

    /**
     * A venue with nothing live is a door with nothing behind it, which is what
     * a fresh server looks like — not a failure.
     */
    public function test_an_experience_with_no_room_contributes_none(): void
    {
        $built = $this->build($this->gallery());

        $this->assertSame([], $built['rooms']);
        $this->assertStringContainsString('hub([])', (string) file_get_contents($this->work.'/out/app.config.ts'));
    }

    /**
     * Uninstalling has to actually uninstall. An artifact that accumulated
     * would keep serving an experience the server no longer offers.
     */
    public function test_a_room_that_is_no_longer_installed_disappears(): void
    {
        $this->build($this->pinball());
        $this->assertFileExists($this->work.'/out/rooms/com.example.pinball/room.ts');

        $this->build($this->gallery());
        $this->assertFileDoesNotExist($this->work.'/out/rooms/com.example.pinball/room.ts');
    }

    private function pinball(): Experience
    {
        return $this->experience('com.example.pinball', $this->work.'/pinball');
    }

    private function gallery(): Experience
    {
        return $this->experience('com.example.gallery', null);
    }

    private function experience(string $name, ?string $room): Experience
    {
        return new class($name, $room) implements Experience
        {
            public function __construct(private readonly string $named, private readonly ?string $roomAt) {}

            public function name(): string
            {
                return $this->named;
            }

            public function room(): ?string
            {
                return $this->roomAt;
            }

            public function title(): string
            {
                return 'Test';
            }

            public function description(): string
            {
                return 'A test';
            }

            public function icon(): string
            {
                return 'sparkles';
            }

            public function route(): string
            {
                return 'test';
            }

            public function action(): ?string
            {
                return null;
            }

            /**
             * @return array{label: string, route: string}|null
             */
            public function watching(): ?array
            {
                return null;
            }

            public function audience(Gathering $gathering): Audience
            {
                return Audience::Anybody;
            }

            public function scopes(): array
            {
                return [];
            }
        };
    }
}
