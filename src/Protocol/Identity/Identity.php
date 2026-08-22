<?php

namespace StreetMesh\Server\Protocol\Identity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use StreetMesh\Protocol\Ed25519;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\SigningKey;

/**
 * Somebody or something with a name and the keys to prove it.
 *
 * A server and a resident are the same kind of thing here, differing only in
 * what they sign. Giving them separate machinery would mean two places to get
 * signing wrong, and the one thing worth having exactly once is the code that
 * decides which bytes a key is applied to.
 *
 * @property int $id
 * @property string $did
 * @property string|null $handle
 * @property string $signing_key
 * @property string $signing_curve
 * @property string|null $rotation_key
 * @property bool $is_server
 */
class Identity extends Model
{
    protected $table = 'streetmesh_identities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Encrypted at rest. A database dump should not be a set of keys.
            'signing_key' => 'encrypted',
            'rotation_key' => 'encrypted',
            'is_server' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The key this identity signs with.
     */
    public function key(): SigningKey
    {
        return $this->restore($this->signing_key);
    }

    /**
     * The key that can move this identity somewhere else, if this server holds
     * one at all.
     *
     * It often should not. A resident whose only rotation key lives here can
     * leave only with this server's cooperation, which is the arrangement the
     * whole project argues against.
     */
    public function rotationKey(): ?SigningKey
    {
        return $this->rotation_key === null ? null : $this->restore($this->rotation_key);
    }

    /**
     * How a signature names the key that checks it.
     *
     * A verifier needs to know which key, not merely whose — a document
     * publishing several would otherwise be checked against whichever is listed
     * first.
     */
    public function keyId(string $fragment = 'atproto'): string
    {
        return $this->did.'#'.$fragment;
    }

    /**
     * Does this identity hold a key that could move it elsewhere?
     */
    public function canBeMoved(): bool
    {
        return $this->rotation_key !== null;
    }

    private function restore(string $stored): SigningKey
    {
        [$public, $secret] = explode(':', $stored, 2);

        return match ($this->signing_curve) {
            'ed25519' => Ed25519::fromStored($public, $secret),
            'p256' => P256::fromStored($public, $secret),
            default => throw new RuntimeException(
                "This server holds a [{$this->signing_curve}] key it cannot use."
            ),
        };
    }
}
