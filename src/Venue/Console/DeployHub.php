<?php

namespace StreetMesh\Server\Venue\Console;

use Illuminate\Console\Command;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Hub\Build;
use StreetMesh\Server\Venue\Hub\Deploy;
use Symfony\Component\Process\Process;

/**
 * Release this server's hub, as part of releasing this server.
 *
 * Belongs in the deploy pipeline, before the venue takes traffic:
 *
 *     php artisan hub:deploy
 *
 * It fails the release when the hub cannot be released, which is the point. A
 * venue serving browsers that talk to a hub which never arrived looks perfectly
 * well and works for nobody.
 */
class DeployHub extends Command
{
    protected $signature = 'hub:deploy
        {--force : Send it even if the hub is already this build}
        {--pretend : Say what would happen and send nothing}';

    protected $description = "Send this server's hub to wherever it runs";

    public function handle(Experiences $experiences): int
    {
        $into = (string) (config('streetmesh.venue.build.into') ?: base_path('hub-build'));
        $from = (string) (config('streetmesh.venue.build.hub') ?: Build::library());
        $hub = (string) config('streetmesh.venue.hub');
        $application = (string) config('streetmesh.venue.deploy.application');
        $token = (string) config('streetmesh.venue.deploy.token');

        $required = [
            'streetmesh.venue.hub' => $hub,
            'streetmesh.venue.deploy.application' => $application,
            'streetmesh.venue.deploy.token' => $token,
        ];

        foreach ($required as $name => $value) {
            if ($value === '') {
                $this->components->error("No {$name}, so there is nowhere to send the hub.");

                return self::FAILURE;
            }
        }

        $built = (new Build($experiences, $from, $into))->run();
        $fingerprint = $built['fingerprint'];

        $this->newLine();
        $this->line('  <fg=gray>build</> '.$fingerprint);

        if (($dirty = $this->uncommitted()) !== null) {
            $this->newLine();
            $this->components->error('This checkout has uncommitted changes.');
            $this->line('  Only what is pushed gets deployed, so this would ship something else —');
            $this->line('  and the CLI stops to ask about it, which in a pipeline is a release that hangs.');
            $this->newLine();
            $this->line($dirty);

            return self::FAILURE;
        }

        if (($stale = $this->stale($into)) !== null) {
            $this->newLine();
            $this->components->error('The hub in this commit is not the hub this server builds.');
            $this->line('  Colyseus deploys what was pushed, so this would ship the old one.');
            $this->line('  Run <fg=yellow>php artisan hub:build</>, commit and push:');
            $this->newLine();
            $this->line($stale);

            return self::FAILURE;
        }

        $deploy = new Deploy(
            endpoint: Deploy::endpointFor($hub),
            applicationId: $application,
            token: $token,
            repository: base_path(),
            branch: $this->branch(),
        );

        $this->line('  <fg=gray>hub  </> '.Deploy::endpointFor($hub).' is '.($deploy->running() ?? 'not answering'));
        $this->newLine();

        if ($this->option('pretend')) {
            $this->components->info(
                $deploy->running() === $fingerprint
                    ? 'Nothing would be sent — the hub is already this build.'
                    : "Would send [{$fingerprint}], which ends every game in progress."
            );

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->components->warn('Sending it regardless, which ends every game in progress.');
        }

        $sent = $deploy->send(
            $fingerprint,
            regardless: (bool) $this->option('force'),
            watching: fn (string $said) => $this->line('  <fg=gray>colyseus</> '.$said),
        );

        if (! $sent['deployed'] && str_contains($sent['why'], 'already this build')) {
            $this->components->info('Nothing to do — '.$sent['why'].'.');

            return self::SUCCESS;
        }

        if (! $sent['deployed']) {
            $this->components->error('The hub was not released: '.$sent['why']);

            return self::FAILURE;
        }

        $this->components->info('Released — '.$sent['why'].'.');

        return self::SUCCESS;
    }

    /**
     * Whether what is committed differs from what this server would build.
     *
     * The one failure this command exists to catch besides an unreachable hub.
     * Colyseus Cloud deploys the pushed commit, not the files sitting here — so
     * a room changed without rebuilding would ship the previous hub while this
     * command waited for a fingerprint that was never sent.
     */
    /**
     * The branch being deployed, which the checkout may not be able to say.
     *
     * A build container checks out a commit, leaving `HEAD` detached and git
     * with no branch name to report. Asked for one anyway, the deploy CLI hands
     * `(no branch)` to a shell on the other side and it fails as a bash syntax
     * error about a bracket — a long way from anything anybody would recognise.
     *
     * So: what git says when git knows, and what the operator says when it does
     * not.
     */
    private function branch(): string
    {
        $git = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], base_path());
        $git->run();

        $named = trim($git->getOutput());

        // `HEAD` is what git answers when it is not on a branch at all.
        if ($git->isSuccessful() && $named !== '' && $named !== 'HEAD') {
            return $named;
        }

        return (string) (config('streetmesh.venue.deploy.branch') ?: 'main');
    }

    /**
     * Anything at all uncommitted, which is a different question from stale.
     *
     * Colyseus deploys the pushed commit. A dirty checkout means the thing
     * being sent is not the thing being looked at — and the CLI stops to ask
     * about it, on a terminal a deploy pipeline does not have.
     */
    private function uncommitted(): ?string
    {
        if (! $this->readable()) {
            return null;
        }

        $git = new Process(['git', 'status', '--porcelain'], base_path());
        $git->run();

        $changed = trim($git->getOutput());

        return ($git->isSuccessful() && $changed !== '') ? $changed : null;
    }

    /**
     * Whether git here is describing this working tree.
     *
     * Laravel Cloud's build detaches the index: `git status` reports every
     * tracked file as staged-deleted and every file as untracked, which reads
     * exactly like a checkout where somebody has thrown everything away. Both
     * checks below are about the difference between what is here and what was
     * pushed, and an index that lists nothing cannot answer that.
     *
     * `git ls-files` is the question asked plainly — it prints what the index
     * holds. Empty means there is nothing to compare against, so the checks
     * abstain rather than refuse. They exist to catch a person deploying
     * something other than what they are looking at, and there is no person.
     */
    private function readable(): bool
    {
        $git = new Process(['git', 'ls-files'], base_path());
        $git->run();

        return $git->isSuccessful() && trim($git->getOutput()) !== '';
    }

    private function stale(string $into): ?string
    {
        if (! $this->readable()) {
            return null;
        }

        $git = new Process(['git', 'status', '--porcelain', '--', $into], base_path());
        $git->run();

        if (! $git->isSuccessful()) {
            // Not a checkout, or no git. Nothing to compare against, and
            // refusing to deploy over it would be worse than proceeding.
            return null;
        }

        $changed = trim($git->getOutput());

        return $changed === '' ? null : $changed;
    }
}
