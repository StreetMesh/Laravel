<?php

namespace StreetMesh\Server\Venue\Hub;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Sending this server's hub to wherever it runs.
 *
 * The venue and its hub are two halves that have to agree — the room code, the
 * browser code that talks to it, and the secret they recognise each other by —
 * so they are released together rather than by two systems on their own
 * schedules.
 *
 * What this cannot be is atomic. Two providers cannot be made to commit
 * together, and pretending otherwise would be worse than saying so. What it is
 * instead is an ordering and a gate: the hub goes first, and the venue's own
 * release fails if the hub's did.
 */
final class Deploy
{
    /**
     * How long to wait for the hub to come back as the build we sent.
     *
     * Generous, because the alternative to waiting is a release that reports
     * success while the two halves disagree.
     */
    private const SECONDS = 180;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $applicationId,
        private readonly string $token,
        /**
         * The repository, not the artifact.
         *
         * The CLI works out what to send from git — which remote, which branch,
         * which commit — and the application itself knows which directory
         * within that to build. Run from the artifact it would be reading the
         * same repository from further down, which is the same answer by a
         * longer route.
         */
        private readonly string $repository,

        /**
         * Which branch to deploy, because the checkout may not know.
         *
         * A build container checks out a commit rather than a branch, so `HEAD`
         * is detached and git has no name to give. The CLI passes whatever it
         * finds straight through to a shell on the other side, where
         * `origin/(no branch)` becomes a syntax error in somebody else's bash.
         */
        private readonly string $branch,
    ) {}

    /**
     * What the hub says it is, or null if it cannot be asked.
     *
     * Null is not "no": a hub that is unreachable might be running anything, so
     * it is never read as agreement.
     */
    public function running(): ?string
    {
        try {
            $answer = Http::timeout(10)->get($this->endpoint.'/build');
        } catch (\Throwable $unreachable) {
            return null;
        }

        return $answer->successful() ? ($answer->json('build') ?: null) : null;
    }

    /**
     * Hand it over, and wait to be told it arrived.
     *
     * @param  null|callable(string): void  $watching  every line the CLI writes
     * @return array{deployed: bool, why: string}
     */
    public function send(string $fingerprint, bool $regardless = false, ?callable $watching = null): array
    {
        // Already the hub we would send. Not deploying is the whole point of
        // asking: a hub restart disposes every room, so a venue release that
        // changed nothing here would still end every game in progress.
        if (! $regardless && $this->running() === $fingerprint) {
            return ['deployed' => false, 'why' => 'the hub is already this build'];
        }

        $deploy = new Process([
            'npx', '--yes', '@colyseus/cloud', 'deploy',
            '--applicationId', $this->applicationId,
            '--token', $this->token,
            '--branch', $this->branch,
        ], $this->repository, timeout: self::SECONDS);

        /*
         * Yes, we meant it.
         *
         * The CLI asks a question when git reports a dirty tree — only pushed
         * files deploy, so it wants to know you meant it. With no input it
         * waits for an answer that never comes, which in a pipeline is a
         * release that hangs.
         *
         * The answer is yes because the question is not really about us. What
         * gets deployed is the pushed commit either way; the prompt is telling
         * you that your local edits will not be included, which in a deploy
         * container is not news. Laravel Cloud's build detaches the git index
         * — every tracked file reads as deleted and every file as untracked —
         * so the question is asked there every single time.
         */
        $deploy->setInput("y\n");

        /*
         * Passed through as it arrives rather than kept for a failure.
         *
         * This is the one step here that happens on somebody else's
         * infrastructure, and the first time it did not work the only thing
         * anybody had to go on was that a hub never appeared. What the CLI
         * said was thrown away because it had exited zero.
         */
        $deploy->run($watching === null ? null : function (string $type, string $line) use ($watching): void {
            foreach (preg_split('/\R/', rtrim($line)) ?: [] as $said) {
                if (trim($said) !== '') {
                    $watching($said);
                }
            }
        });

        if (! $deploy->isSuccessful()) {
            return ['deployed' => false, 'why' => trim($deploy->getErrorOutput() ?: $deploy->getOutput())];
        }

        return $this->waitFor($fingerprint);
    }

    /**
     * Until the hub answers with what we sent it.
     *
     * Colyseus Cloud reports that it accepted a deployment, not that the thing
     * is up and serving. The hub can say which build it is, so that is what is
     * believed — and a release that carried on regardless would be a venue
     * telling browsers to talk to a hub that is not there yet.
     *
     * @return array{deployed: bool, why: string}
     */
    private function waitFor(string $fingerprint): array
    {
        $until = time() + self::SECONDS;

        while (time() < $until) {
            sleep(5);

            if ($this->running() === $fingerprint) {
                return ['deployed' => true, 'why' => 'the hub is serving this build'];
            }
        }

        return [
            'deployed' => false,
            'why' => "the hub did not come back as [{$fingerprint}] within ".self::SECONDS.'s',
        ];
    }

    /**
     * Where to ask, from where the browsers are told to connect.
     *
     * One setting rather than two. A hub reached at one address and asked about
     * at another is two things that can drift, and the way you find out they
     * have is a deploy that waits three minutes for a hub that was fine.
     */
    public static function endpointFor(string $hub): string
    {
        return (string) preg_replace('#^ws(s)?://#', 'http$1://', rtrim($hub, '/'));
    }
}
