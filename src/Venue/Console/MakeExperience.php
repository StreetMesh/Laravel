<?php

namespace StreetMesh\Server\Venue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

/**
 * Start an experience.
 *
 * An experience is a Composer package a venue operator installs, and that is
 * the whole reason this exists: the shape of one is not obvious, most of it is
 * the same every time, and the parts that are the same are exactly the parts
 * that fail silently when they are wrong. A missing `Livewire::addNamespace`
 * is a component that cannot be found while the view plainly exists. A missing
 * `extra.streetmesh` is a screen with no styling and no explanation.
 *
 * So this writes the boilerplate and leaves the decisions. What comes out is a
 * package that installs, registers, appears on the menu, and passes its own
 * tests — and does nothing, which is where the author starts.
 */
class MakeExperience extends Command
{
    protected $signature = 'streetmesh:experience
        {name? : The Composer package name, like acme/laravel-bingo}
        {--nsid= : The reverse-domain name, like com.acme.games.bingo}
        {--title= : What it is called on the menu}
        {--into= : Where to write it, defaulting to alongside this application}
        {--room : It has rules that run live, in the hub}
        {--force : Write over a directory that is already there}';

    protected $description = 'Scaffold a new experience as a standalone Composer package';

    public function handle(): int
    {
        $package = (string) ($this->argument('name') ?: text(
            label: 'What is the package called?',
            placeholder: 'acme/laravel-bingo',
            required: true,
            validate: fn (string $value): ?string => preg_match('#^[a-z0-9._-]+/[a-z0-9._-]+$#', $value) === 1
                ? null
                : 'A Composer package name looks like vendor/name, lower case.',
        ));

        [$vendor, $shortName] = explode('/', $package, 2);

        $class = Str::studly(Str::replaceFirst('laravel-', '', $shortName));
        $slug = Str::kebab($class);

        $nsid = (string) ($this->option('nsid') ?: text(
            label: 'And its reverse-domain name?',
            placeholder: "com.{$vendor}.{$slug}",
            default: "com.{$vendor}.{$slug}",
            hint: 'One name for three things: the records, the room type, and the experience.',
        ));

        $title = (string) ($this->option('title') ?: text(
            label: 'What is it called on the menu?',
            placeholder: $class,
            default: $class,
        ));

        $description = text(
            label: 'One sentence, for somebody deciding whether to go in',
            placeholder: 'Do a thing with people who live somewhere else.',
            default: 'Do a thing with people who live somewhere else.',
        );

        $icon = text(
            label: 'A Flux icon name, for the gallery',
            placeholder: 'sparkles',
            default: 'sparkles',
        );

        $hasRoom = $this->option('room') || confirm(
            label: 'Does it have rules that run live?',
            default: false,
            hint: 'A game does. A gallery or a reading room does not, and needs no hub room.',
        );

        $into = rtrim((string) ($this->option('into') ?: base_path('../'.$shortName)), '/');

        if (is_dir($into) && ! $this->option('force')) {
            $this->components->error("[{$into}] is already there. Pass --force to write over it.");

            return self::FAILURE;
        }

        $replacements = [
            '{{ package }}' => $package,
            /*
             * Two spellings of one name, because they land in two languages.
             * PHP source wants `Acme\Bingo`; JSON wants every backslash
             * doubled. Writing one of them into the other produces a namespace
             * that looks almost right and autoloads nothing.
             */
            '{{ namespace }}' => Str::studly($vendor).'\\'.$class,
            '{{ namespaceJson }}' => Str::studly($vendor).'\\\\'.$class,
            '{{ class }}' => $class,
            '{{ slug }}' => $slug,
            '{{ nsid }}' => $nsid,
            '{{ title }}' => $title,
            '{{ description }}' => $description,
            '{{ icon }}' => $icon,
            '{{ roomPackage }}' => '@'.$vendor.'/'.$slug.'-room',

            /*
             * `Settles` is a separate interface because most things do not
             * conclude. A shop has no result and a gallery never ends, so an
             * interface every experience implemented would be one most of them
             * implemented with an empty method. Left off here — adding it later
             * is one `implements` and one method.
             */
            '{{ settlesInterface }}' => '',
            '{{ roomMethod }}' => $this->roomMethod($hasRoom),
        ];

        $this->write($into, $replacements, $hasRoom);

        $this->format($into);

        $this->say($package, $into, $slug, $hasRoom);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function write(string $into, array $replacements, bool $hasRoom): void
    {
        $stubs = __DIR__.'/../../../stubs/experience';

        if (! is_dir($stubs)) {
            throw new RuntimeException("No stubs at [{$stubs}].");
        }

        $files = [
            'composer.json.stub' => 'composer.json',
            'gitignore.stub' => '.gitignore',
            'phpunit.xml.stub' => 'phpunit.xml',
            'phpstan.neon.stub' => 'phpstan.neon',
            'src/Experience.php.stub' => 'src/'.$replacements['{{ class }}'].'Experience.php',
            'src/ServiceProvider.php.stub' => 'src/'.$replacements['{{ class }}'].'ServiceProvider.php',
            'routes/web.php.stub' => 'routes/web.php',
            'resources/js/alpine.js.stub' => 'resources/js/alpine.js',
            'resources/views/livewire/lobby.blade.php.stub' => 'resources/views/livewire/lobby.blade.php',
            'tests/TestCase.php.stub' => 'tests/TestCase.php',
            'tests/OfflineNetwork.php.stub' => 'tests/OfflineNetwork.php',
            'tests/ExperienceTest.php.stub' => 'tests/'.$replacements['{{ class }}'].'Test.php',
            'tests/fixtures/views/layouts/app.blade.php.stub' => 'tests/fixtures/views/layouts/app.blade.php',
        ];

        if ($hasRoom) {
            $files['room/package.json.stub'] = 'room/package.json';
            $files['room/src/room.ts.stub'] = 'room/src/room.ts';
        }

        foreach ($files as $from => $to) {
            $target = $into.'/'.$to;

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            file_put_contents($target, strtr((string) file_get_contents($stubs.'/'.$from), $replacements));
        }
    }

    /**
     * Tidy what was written, with whatever this application already has.
     *
     * The author's own namespace sorts wherever it sorts — `Acme\\Bingo` comes
     * before `Flux`, `Zebra\\Bingo` after `StreetMesh` — so a stub cannot put
     * its imports in an order that is right for everybody. Rather than emit
     * something that fails its own `composer test` on the first run, format it
     * once, here, against the rules this project already uses.
     *
     * Skipped in silence when there is no Pint to hand. A package that is
     * correct and unformatted is a smaller problem than a command that refuses
     * to finish.
     */
    private function format(string $into): void
    {
        $pint = base_path('vendor/bin/pint');

        if (! is_executable($pint)) {
            return;
        }

        exec(escapeshellarg($pint).' '.escapeshellarg($into).' --quiet 2>/dev/null');
    }

    /**
     * Where this experience's room lives, or that it has none.
     *
     * Null is an ordinary answer and worth saying so in the generated file — a
     * gallery is a perfectly good experience with nobody to keep in step.
     */
    private function roomMethod(bool $hasRoom): string
    {
        if (! $hasRoom) {
            return <<<'PHP'
                /**
                 * Nothing about this is live, so there is no room to serve.
                 *
                 * An ordinary answer. If that changes, return an absolute path
                 * to a directory holding a Node package whose entry point
                 * default-exports `{ name, room }` — the venue copies it into
                 * the hub it builds and never imports it.
                 */
                public function room(): ?string
                {
                    return null;
                }

            PHP;
        }

        return <<<'PHP'
            /**
             * The rules, which are not written in PHP and do not run here.
             *
             * An absolute path to a Node package whose entry point default-
             * exports `{ name, room }`. The venue copies it into the hub it
             * builds; nothing in PHP runs a line of what is inside.
             *
             * Declared rather than discovered, because going looking would mean
             * assuming a directory layout that belongs to somebody else.
             *
             * Narrowed from the interface's nullable, because this one always
             * has an answer — null there means "nothing about me is live".
             */
            public function room(): string
            {
                return realpath(__DIR__.'/../room') ?: __DIR__.'/../room';
            }

        PHP;
    }

    private function say(string $package, string $into, string $slug, bool $hasRoom): void
    {
        $this->newLine();
        $this->components->info("Wrote {$package} to {$into}");

        note(
            "Install it into this server:\n\n".
            "    composer config repositories.{$slug} path {$into}\n".
            "    composer require {$package}:@dev\n"
        );

        note(
            "Two things that fail quietly, so they are worth knowing before they do.\n\n".
            "Vite reads what a package declares when it starts. Yours will not appear\n".
            "on the page until you restart it — and the failure is an unstyled screen\n".
            "with no error anywhere.\n\n".
            'And `Livewire::addNamespace` is not `loadViewsFrom`. Both are in the '.
            "provider already; removing either leaves a component that cannot be\n".
            'found while the view plainly exists.'
        );

        if ($hasRoom) {
            note(
                "Your room ships its own dependencies, but the *application* installs them —\n".
                'add them to its `package.json` too, then `php artisan hub:build`.'
            );
        }
    }
}
