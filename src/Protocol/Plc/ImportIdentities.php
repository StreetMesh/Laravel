<?php

namespace StreetMesh\Server\Protocol\Plc;

use Illuminate\Console\Command;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Server\Protocol\Identity\Identity;
use Throwable;

/**
 * Bring identities in from another directory.
 *
 * Written for one afternoon in particular — moving off a directory that ran in
 * Docker and onto the one this server keeps itself — but it is not only for
 * that. Mirroring a real identity locally is the same operation, and so is
 * standing a second development machine up with the same residents on it.
 *
 * Every operation is replayed through `Directory::submit` rather than copied
 * into the table, which is the point rather than caution. An import that
 * succeeds has re-derived each identifier from its genesis, re-checked every
 * signature, and re-linked every `prev` — so it is also the strongest available
 * check that our reading of the method agrees with the software the real
 * directory runs. An import that fails has found a disagreement, and that is
 * worth knowing on an afternoon rather than on a Friday.
 */
class ImportIdentities extends Command
{
    protected $signature = 'plc:import
        {did?* : The identities to bring in}
        {--from= : The directory to read them from}
        {--all : Every did:plc resident this server knows about}';

    protected $description = 'Bring identities in from another PLC directory';

    public function handle(Directory $directory, Network $network): int
    {
        if (! $directory->hosting()) {
            $this->components->error(
                'This server keeps no directory, so there is nowhere to put them. Set STREETMESH_PLC_HOST=true.'
            );

            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?: config('streetmesh.plc.directory'));

        /*
         * Reading from the directory we are writing to would import each
         * identity from itself, which does nothing and looks like it worked.
         */
        if (rtrim($from, '/') === rtrim((string) url('/plc'), '/')) {
            $this->components->error('That is this server\'s own directory. Name the one to read from with --from.');

            return self::FAILURE;
        }

        $dids = $this->whichIdentities();

        if ($dids === []) {
            $this->components->warn('No did:plc identities to bring in.');

            return self::SUCCESS;
        }

        $source = new PlcDirectory($network, $from);
        $failed = 0;

        foreach ($dids as $did) {
            $failed += $this->bringIn($source, $directory, $did) ? 0 : 1;
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$failed} of ".count($dids).' did not come across.');

            return self::FAILURE;
        }

        $this->components->info(count($dids).' brought in from '.$from.'.');

        return self::SUCCESS;
    }

    private function bringIn(PlcDirectory $source, Directory $directory, string $did): bool
    {
        if ($directory->head($did) !== null) {
            $this->line("  <fg=gray>already here</> {$did}");

            return true;
        }

        try {
            $log = $source->auditLog($did);
        } catch (Throwable $unreachable) {
            $this->line("  <fg=red>could not read</> {$did} — {$unreachable->getMessage()}");

            return false;
        }

        foreach ($log as $entry) {
            /*
             * Nullified operations are the record of a recovery, and this
             * directory has no way to express one — replaying it as an
             * ordinary operation would assert as current something that was
             * explicitly undone.
             */
            if ($entry['nullified'] ?? false) {
                $this->line("  <fg=yellow>stopping at a recovery</> {$did}");

                return false;
            }

            try {
                $directory->submit($did, $entry['operation']);
            } catch (Throwable $refused) {
                $this->line("  <fg=red>refused</> {$did} — {$refused->getMessage()}");

                return false;
            }
        }

        $this->line('  <fg=green>brought in</> '.$did.' <fg=gray>('.count($log).' operations)</>');

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function whichIdentities(): array
    {
        /** @var array<int, string> $named */
        $named = (array) $this->argument('did');

        if ($named !== []) {
            return $named;
        }

        if (! $this->option('all')) {
            $this->components->warn('Name an identity, or pass --all.');

            return [];
        }

        return Identity::query()
            ->where('did', 'like', 'did:plc:%')
            ->pluck('did')
            ->all();
    }
}
