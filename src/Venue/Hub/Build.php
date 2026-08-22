<?php

namespace StreetMesh\Server\Venue\Hub;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use StreetMesh\Server\Venue\Experiences\Experiences;

/**
 * This server's hub, written out as a program that can be deployed.
 *
 * A StreetMesh server has at most one hub, and the hub is just Colyseus. What
 * makes one hub different from another is the rooms it serves — and only the
 * server knows which those are, because it is the thing the experiences were
 * installed into. So the server emits it.
 *
 * Flat and self-contained on purpose. Everything is copied in: no submodules,
 * no path repositories, no dependency resolved from a git host at deploy time.
 * A hub built from local rooms and a published copy of everything else is the
 * same trap as a package tested against its own stale vendor directory — it
 * looks right until the two disagree, and then it is somebody else's afternoon.
 */
final class Build
{
    /**
     * Where the room's own imports of the hub have to end up pointing.
     *
     * A room is written against `@streetmesh/hub`, which is the honest name for
     * it while the room is a package of its own. Inside the artifact there are
     * no packages, so the one import that names it is repointed at the copy
     * sitting next to it.
     */
    private const HUB_PACKAGE = '@streetmesh/hub';

    /** @var array<int, string> */
    private array $rooms = [];

    public function __construct(
        private readonly Experiences $experiences,
        private readonly string $hub,
        private readonly string $into,
    ) {}

    /**
     * @return array{fingerprint: string, rooms: array<int, string>}
     */
    public function run(): array
    {
        if (! is_dir($this->hub.'/src')) {
            throw new RuntimeException("No hub library at [{$this->hub}]. Set streetmesh.venue.build.hub.");
        }

        $this->clear();

        $this->copy($this->hub.'/src', $this->into.'/hub');

        foreach ($this->experiences->all() as $experience) {
            $room = $experience->room();

            // An experience with nothing live is ordinary. A gallery has no
            // room, and a hub serving none is a door with nothing behind it.
            if ($room === null) {
                continue;
            }

            if (! is_dir($room.'/src')) {
                throw new RuntimeException("[{$experience->name()}] says its room is at [{$room}], which has no src.");
            }

            $this->copy($room.'/src', $this->into.'/rooms/'.$experience->name());
            $this->rooms[] = $experience->name();
        }

        $this->write('app.config.ts', $this->appConfig());
        $this->write('index.ts', $this->entryPoint());
        $this->write('package.json', $this->packageJson());
        $this->write('tsconfig.json', $this->tsconfig());
        $this->write('.gitignore', "node_modules\n");

        /*
         * Last, and left out of the fingerprint, because these are the files
         * that carry it.
         *
         * `build.json` is what the running hub reads. It used to be an
         * environment variable set by the process manager, which keeps the
         * environment a process started with — so a redeployed hub ran new code
         * and went on reporting the build it replaced. A file is part of the
         * artifact and cannot be older than the code beside it.
         */
        $fingerprint = $this->fingerprint();
        $this->write('build.json', (string) json_encode(['build' => $fingerprint])."\n");
        $this->write('ecosystem.config.cjs', $this->ecosystem($fingerprint));

        return ['fingerprint' => $fingerprint, 'rooms' => $this->rooms];
    }

    /**
     * Written fresh every time.
     *
     * A room removed from the server has to disappear from its hub, and an
     * artifact that accumulated would keep serving an experience nobody
     * installs any more.
     */
    private function clear(): void
    {
        if (! is_dir($this->into)) {
            mkdir($this->into, 0755, true);

            return;
        }

        foreach (['hub', 'rooms'] as $directory) {
            $this->remove($this->into.'/'.$directory);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private function copy(string $from, string $to): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir()) {
                continue;
            }

            $within = substr($entry->getPathname(), strlen($from) + 1);
            $target = $to.'/'.$within;

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            file_put_contents($target, $this->repointed((string) file_get_contents($entry->getPathname()), $target));
        }
    }

    /**
     * The one import that names the hub as a package, made relative.
     *
     * Computed from where the file landed rather than assumed, so a room with
     * source in subdirectories works the same as one with a single file.
     */
    private function repointed(string $source, string $target): string
    {
        if (! str_contains($source, self::HUB_PACKAGE)) {
            return $source;
        }

        $up = str_repeat('../', substr_count(substr(dirname($target), strlen($this->into) + 1), '/') + 1);

        return str_replace("'".self::HUB_PACKAGE."'", "'".$up."hub/mod.ts'", $source);
    }

    /**
     * What the whole artifact adds up to.
     *
     * Sorted, and over contents rather than timestamps, so building twice from
     * the same source gives the same answer. That is the whole point: a deploy
     * asks a running hub what it is and does nothing when the answer already
     * matches — and a hub restart ends every game in progress.
     */
    private function fingerprint(): string
    {
        $files = [];

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->into, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir()) {
                continue;
            }

            $within = substr($entry->getPathname(), strlen($this->into) + 1);

            if ($this->countsTowards($within)) {
                $files[$within] = hash_file('sha256', $entry->getPathname());
            }
        }

        ksort($files);

        return substr(hash('sha256', (string) json_encode($files)), 0, 16);
    }

    /**
     * What the fingerprint is taken over: this hub, and nothing about the
     * machine that built it.
     *
     * `ecosystem.config.cjs` is the file that carries the fingerprint, and
     * hashing it would mean hashing the previous answer into the next one. Two
     * builds from identical sources then differ every time, which does not look
     * like a bug — it looks like the hub always needing to be redeployed, and
     * every deploy ends the games in progress.
     */
    private function countsTowards(string $within): bool
    {
        return $within !== 'ecosystem.config.cjs'
            && $within !== 'build.json'
            && $within !== 'package-lock.json'
            && ! str_starts_with($within, 'node_modules/');
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->into.'/'.$name, $contents);
    }

    private function appConfig(): string
    {
        $imports = '';
        $listed = [];

        foreach ($this->rooms as $index => $name) {
            // The room's own default export, passed through whole. It names
            // itself, and that name is the NSID the venue signs tickets for —
            // restating it here would be a second place for them to disagree.
            $imports .= "import experience{$index} from './rooms/{$name}/room.ts'\n";
            $listed[] = "  experience{$index},";
        }

        $rooms = $listed === [] ? '[]' : "[\n".implode("\n", $listed)."\n]";

        return <<<TS
        /**
         * This server's hub.
         *
         * Generated by `php artisan hub:build` from the experiences installed on
         * the venue that runs it. Nobody edits this file; edit the server.
         *
         * The transport is deliberately not named. Colyseus picks one, which is
         * what we want today, and WebTransport later is this file and nothing
         * else — which is the reason the hub stopped standing up a server of its
         * own.
         *
         * `@colyseus/tools` exports its `config()` as a CommonJS default, which
         * a type checker reading this as an ES module cannot call. It only
         * validates the options and hands them back, and `listen` takes them
         * either way — so the options are exported directly rather than
         * carrying a build failure for a function that does nothing here.
         */

        import { hub } from './hub/mod.ts'
        {$imports}
        export default hub({$rooms})

        TS;
    }

    /**
     * The two lines that turn the options into a running server.
     *
     * Kept apart from `app.config.ts` the way every Colyseus project keeps
     * them: one file says what the application is, another starts it. The
     * checks in the hub library start the same options a different way, which
     * is only safe because the options are the thing being shared.
     */
    private function entryPoint(): string
    {
        return <<<'TS'
        // Generated by `php artisan hub:build`. Nobody edits this file.

        import { readFileSync } from 'node:fs'
        import { listen } from '@colyseus/tools'
        import app from './app.config.ts'

        /*
         * Which build this is, read from the artifact rather than the
         * environment. A process manager hands a process the environment it
         * started with, so a redeployed hub reported the build it replaced —
         * and a deploy waiting for the new one waited forever.
         */
        process.env.HUB_BUILD = JSON.parse(
          readFileSync(new URL('./build.json', import.meta.url), 'utf8'),
        ).build

        await listen(app)

        TS;
    }

    private function packageJson(): string
    {
        return (string) json_encode([
            'name' => 'streetmesh-hub',
            'private' => true,
            'type' => 'module',
            'description' => 'Generated. This server\'s hub, with the rooms it has installed.',
            'scripts' => [
                // What a host runs before starting it. Nothing is emitted —
                // the sources run as they are — so this is the type check,
                // which is the useful thing to fail a deploy on.
                'build' => 'tsc --noEmit',
                'start' => 'node --experimental-strip-types index.ts',
            ],
            'devDependencies' => [
                'typescript' => '^5.7.0',
                '@types/node' => '^22.10.0',
                // The hub types its two routes against Express, which is what
                // `@colyseus/tools` hands them.
                '@types/express' => '^5.0.6',
            ],
            'dependencies' => $this->dependencies(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * What the hub needs, plus what each room brought with it.
     *
     * Read from the packages rather than listed here, so an experience that
     * needs a chess engine says so in its own `package.json` and this does not
     * have to know what chess is.
     *
     * @return array<string, string>
     */
    private function dependencies(): array
    {
        $needed = $this->declared($this->hub.'/package.json');

        foreach ($this->experiences->all() as $experience) {
            $room = $experience->room();

            if ($room !== null) {
                $needed = [...$needed, ...$this->declared($room.'/package.json')];
            }
        }

        // The generated config calls it, and the hub library only ever imported
        // its types.
        $needed['@colyseus/tools'] = $needed['@colyseus/tools'] ?? '^0.16.20';

        ksort($needed);

        return $needed;
    }

    /**
     * @return array<string, string>
     */
    private function declared(string $manifest): array
    {
        if (! is_file($manifest)) {
            return [];
        }

        /** @var array{dependencies?: array<string, string>} $package */
        $package = json_decode((string) file_get_contents($manifest), true) ?: [];

        return $package['dependencies'] ?? [];
    }

    private function tsconfig(): string
    {
        return (string) json_encode([
            'compilerOptions' => [
                'target' => 'ES2023',
                'module' => 'NodeNext',
                'moduleResolution' => 'NodeNext',
                'allowImportingTsExtensions' => true,
                'noEmit' => true,
                'strict' => true,
                'skipLibCheck' => true,
                'types' => ['node'],
            ],
            'include' => ['**/*.ts'],
            'exclude' => ['node_modules'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * How a process manager starts it, and where the fingerprint lives.
     *
     * `.cjs` because the package is a module and PM2 reads this file as
     * CommonJS. Named in the environment rather than written into a source file
     * so that asking a running hub which build it is costs it nothing.
     */
    private function ecosystem(string $fingerprint): string
    {
        return <<<JS
        // Generated by `php artisan hub:build`. Nobody edits this file.
        module.exports = {
          apps: [
            {
              name: 'streetmesh-hub',
              script: 'index.ts',
              interpreter: 'node',
              interpreter_args: '--experimental-strip-types',
              instances: 1,
              exec_mode: 'fork',
              env: {
                NODE_ENV: 'production',
                HUB_BUILD: '{$fingerprint}',
              },
            },
          ],
        }

        JS;
    }
}
