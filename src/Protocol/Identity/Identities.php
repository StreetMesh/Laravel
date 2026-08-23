<?php

namespace StreetMesh\Server\Protocol\Identity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use StreetMesh\Protocol\Did;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Plc;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Protocol\SigningKey;

/**
 * Bringing identities into being.
 *
 * Two methods, and the choice is consequential rather than cosmetic. `did:web`
 * needs nothing but a hostname you already control, which makes it right for
 * development and for a server content to be found where it lives. `did:plc`
 * costs a public entry in a directory and buys two things `did:web` cannot
 * offer at all: an identifier that survives its subject moving, and a dated
 * history of keys so that something signed years ago can still be checked.
 *
 * Minting a `did:plc` publishes to shared infrastructure and cannot be undone,
 * so it is never a side effect of anything here — it is asked for explicitly.
 */
final class Identities
{
    public function __construct(
        private readonly PlcDirectory $directory,
        private readonly string $host,
        private readonly string $defaultCurve = 'p256',
    ) {}

    /**
     * This server's own identity, made once and then found.
     *
     * A venue signs attestations with it and a domicile answers for itself with
     * it, so nothing federated works until it exists — which is why it is
     * created on demand rather than being a step somebody can forget.
     */
    public function forServer(): Identity
    {
        $existing = Identity::query()->where('is_server', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $key = $this->generate();

        return Identity::create([
            'did' => (string) Did::forHost($this->host),
            'handle' => $this->host,
            'signing_key' => $this->store($key),
            'signing_curve' => $key->curve(),
            'is_server' => true,
        ]);
    }

    /**
     * An identity for somebody this server hosts.
     *
     * `did:plc`, where the server's own identity is `did:web`, and the
     * asymmetry is deliberate. A server is a thing at an address and is
     * reasonably found there. A person is not: they may want a different name
     * next year, or a different server, and neither should cost them the
     * records they have already signed. A `did:web` cannot survive either,
     * because a `did:web` *is* the address.
     *
     * @return array{identity: Identity, rotationKey: SigningKey} the rotation
     *                                                            key is theirs,
     *                                                            and is not
     *                                                            stored here
     */
    public function forResident(string $handle, ?Model $owner = null): array
    {
        if (Identity::query()->where('handle', $handle)->exists()) {
            throw new RuntimeException("[{$handle}] is already taken here.");
        }

        $signing = $this->generate();

        /*
         * Two rotation keys, and which comes first decides who can overrule
         * whom. PLC treats the list as an order of authority: an operation
         * signed by a higher key can undo one signed by a lower.
         *
         * Theirs is first and is handed to them rather than kept, so moving out
         * never needs this server's cooperation — a rotation key held only here
         * would make leaving a favour. This server's is second, which is enough
         * to change their handle for them and not enough to overrule them if
         * they would rather it did not.
         */
        $theirs = $this->generate();
        $ours = $this->generate();

        $genesis = Plc::genesis(
            rotationKeys: [$theirs, $ours],
            signingKey: $signing,
            handle: $handle,
            serviceEndpoint: $this->origin(),
        );

        $did = Plc::did($genesis);

        /*
         * Published before it is stored. A directory that refused leaves this
         * server holding nothing, which is recoverable; an identity stored here
         * and never published would be a resident whose name resolves to a DID
         * nobody in the world can look up.
         */
        $this->directory->submit($did, $genesis);

        /*
         * Both rows together, and only now — the submit above is deliberately
         * left outside. A server that hosts its own directory, which
         * `STREETMESH_PLC_HOST` makes an ordinary thing to be, submits over HTTP
         * to itself, and a transaction opened before that call is still holding
         * the database when the request arrives. The request waits for the lock,
         * the call waits for the request, and nothing breaks the tie but the
         * HTTP timeout — which then reports a directory that refused, naming the
         * one part of this that was working.
         */
        $identity = DB::transaction(function () use ($did, $handle, $signing, $ours, $owner): Identity {
            $identity = Identity::create([
                'did' => $did,
                'handle' => $handle,
                'signing_key' => $this->store($signing),
                'signing_curve' => $signing->curve(),

                // Ours, not theirs. Theirs is returned below and kept nowhere.
                'rotation_key' => $this->store($ours),
                'is_server' => false,
            ]);

            /*
             * An identity with nobody attached is a name that cannot be signed
             * in as and claimed, so when there is somebody it goes down with the
             * row rather than after it.
             */
            if ($owner !== null) {
                $identity->owner()->associate($owner)->save();
            }

            return $identity;
        });

        return ['identity' => $identity, 'rotationKey' => $theirs];
    }

    /**
     * Where the repositories this server holds are reached.
     */
    public function origin(): string
    {
        return (string) (config('streetmesh.origin') ?? 'https://'.$this->host);
    }

    public function byDid(string $did): ?Identity
    {
        return Identity::query()->where('did', $did)->first();
    }

    /**
     * The identity belonging to somebody signed in here.
     *
     * A person's account and their identity are different things, and the gap
     * matters: an account is how they get into this server, and an identity is
     * who they are to every other one. Somebody can hold an account here and
     * have no identity — a fresh install, or a server that has not started
     * issuing them — and that is a real state rather than an error.
     *
     * Never the server's own. A server has an identity too, and handing it back
     * for whoever happens to be signed in would let a person act as the server.
     */
    public function forUser(Model $user): ?Identity
    {
        return Identity::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('is_server', false)
            ->first();
    }

    public function byHandle(string $handle): ?Identity
    {
        return Identity::query()->where('handle', strtolower(ltrim(trim($handle), '@')))->first();
    }

    private function generate(): SigningKey
    {
        return match ($this->defaultCurve) {
            /*
             * P-256 by default, because it is the only curve that works both for
             * did:web now and did:plc later — an identity minted on Ed25519
             * could never move to the method that makes it portable.
             */
            'p256' => P256::generate(),
            'ed25519' => Ed25519::generate(),
            default => throw new RuntimeException("This server cannot make [{$this->defaultCurve}] keys."),
        };
    }

    private function store(SigningKey $key): string
    {
        return match (true) {
            $key instanceof P256 => base64_encode($key->publicKey()).':'.$key->secretKey(),
            $key instanceof Ed25519 => $key->publicKey().':'.$key->secretKey(),
            default => throw new RuntimeException('That key cannot be stored.'),
        };
    }
}
