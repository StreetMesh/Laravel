<?php

namespace StreetMesh\Server\Domicile\Residents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Protocol\SigningKey;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;

/**
 * Giving somebody who lives here an address.
 *
 * An account and an address are different things, and this is what joins them.
 * The account is local — a password, a session, a way back in. The address is
 * not: it is a name other servers resolve, a key they check signatures against,
 * and the only thing that makes this person able to go anywhere on the network.
 *
 * A domicile that hands out accounts without addresses has residents who cannot
 * leave the building. That is what this server did until now, and nothing
 * reported it, because everything local worked.
 */
final readonly class Residents
{
    public function __construct(
        private Identities $identities,
        private PlcDirectory $directory,
    ) {}

    /**
     * Settle somebody at a name, and hand back what lets them leave.
     *
     * The rotation key is returned and deliberately not kept. Holding the only
     * copy of what lets somebody move would make moving a favour this server
     * grants — so whether it is stored, and where, is the caller's decision to
     * make in the open rather than one made quietly here.
     *
     * @return array{identity: Identity, rotationKey: SigningKey}
     */
    public function settle(Model $user, Handle $handle): array
    {
        if ($this->identities->forUser($user) !== null) {
            throw new RuntimeException('Somebody with an address cannot be given a second one.');
        }

        /*
         * A resident is an account *and* an identity, and either half alone is
         * somebody who cannot be dealt with — an account with no address cannot
         * go anywhere, and an address with no owner is a name nobody can sign in
         * as and claim. So the owner is handed over to be written down with the
         * identity rather than attached to it afterwards.
         *
         * Not wrapped in a transaction here, the way `rename` is not: publishing
         * to the directory is a network call, and holding the database open
         * across one is what deadlocks a server that hosts its own.
         */
        return $this->identities->forResident((string) $handle, $user);
    }

    /**
     * Change the name somebody is known by.
     *
     * What `did:web` could not do, and most of the reason residents are not
     * `did:web`. Their identifier does not move, so every record they have
     * already signed stays theirs and anybody holding one can still resolve it.
     * Only the name people type changes.
     *
     * Signed with this server's rotation key, which is the lower of the two on
     * their record. If they would rather this server had not, the key they were
     * handed at sign-up overrules it.
     */
    public function rename(Model $user, Handle $handle): Identity
    {
        $identity = $this->identities->forUser($user)
            ?? throw new RuntimeException('Nobody without an address can change it.');

        if ($this->taken($handle)) {
            throw new RuntimeException('Somebody here already has that address.');
        }

        $ours = $identity->rotationKey()
            ?? throw new RuntimeException(
                'This server holds no rotation key for that identity, so it cannot change the name on it.'
            );

        $log = $this->directory->auditLog($identity->did);
        $head = end($log)['operation'] ?? throw new RuntimeException('That identity has no history to add to.');

        $this->directory->submit($identity->did, Plc::rename($head, $ours, (string) $handle));

        /*
         * Written down here only after the directory has taken it. The record
         * out there is the one anybody else reads; this row is a local copy of
         * it, and a copy that ran ahead would be this server disagreeing with
         * the network about somebody's name.
         */
        $identity->update(['handle' => (string) $handle]);

        return $identity;
    }

    /**
     * Everybody who lives here.
     *
     * Ordered by name because that is what somebody is scanning for. The
     * server's own identity is not a resident and never appears: it holds no
     * records of its own and belongs to nobody.
     *
     * @return Collection<int, Identity>
     */
    public function all(string $matching = ''): Collection
    {
        $matching = trim($matching);

        return Identity::query()
            ->where('is_server', false)
            ->when(
                $matching !== '',
                fn (Builder $query) => $query->where('handle', 'like', '%'.$matching.'%'),
            )
            ->orderBy('handle')
            ->get();
    }

    /**
     * Whether a name is free to be taken.
     *
     * Checked here as well as by the database, because the useful answer at a
     * sign-up form is a sentence rather than an integrity violation.
     */
    public function taken(Handle $handle): bool
    {
        return $this->identities->byHandle((string) $handle) !== null;
    }

    /**
     * The host every resident's name sits under — this server's own.
     *
     * Taken from the server's identity rather than from configuration, because
     * it has to match the name the server answers to. If the two disagreed,
     * residents would be issued names this server does not serve.
     */
    public function host(): string
    {
        return $this->identities->forServer()->handle
            ?? throw new RuntimeException('This server has no name of its own, so it cannot give anybody an address under it.');
    }
}
