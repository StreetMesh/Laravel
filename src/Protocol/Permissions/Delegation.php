<?php

namespace StreetMesh\Server\Protocol\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use StreetMesh\Protocol\BlobScope;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Scope;

/**
 * Permission this server holds over somebody else's records.
 *
 * A visitor arrived from a domicile we had never heard of, and this is what we
 * were given: their name, a token, and the key that token is bound to. It is
 * borrowed rather than owned — they can take it back at any moment, and the
 * first we will hear of that is a refusal.
 *
 * @property int $id
 * @property string|null $did
 * @property string $handle
 * @property string $issuer
 * @property string $dpop_key
 * @property string|null $state
 * @property string|null $code_verifier
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string $scope
 * @property Carbon|null $expires_at
 */
class Delegation extends Model
{
    protected $table = 'streetmesh_delegations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            /*
             * Encrypted at rest, all four. A token is spendable and a private
             * key is the thing that makes it spendable, so a leaked table
             * should not be a leaked visitor.
             */
            'dpop_key' => 'encrypted',
            'code_verifier' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The key every request under this delegation is signed with.
     */
    public function key(): P256
    {
        [$public, $private] = explode('|', $this->dpop_key, 2);

        return P256::fromStored($public, $private);
    }

    public static function store(P256 $key): string
    {
        return base64_encode($key->publicKey()).'|'.$key->secretKey();
    }

    /**
     * Worth refreshing before it is worth using, because a token that expires
     * between the check and the request is a failure a visitor would see.
     */
    public function isStale(): bool
    {
        /*
         * Compared against a moment rather than by shifting this one. Carbon's
         * `subSeconds` mutates, so asking twice would have moved the expiry an
         * extra minute into the past each time — a token that grew staler for
         * being looked at.
         */
        return $this->expires_at === null || $this->expires_at->isBefore(now()->addSeconds(60));
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return explode(' ', $this->scope);
    }

    public function permits(string $collection, string $action = Scope::CREATE): bool
    {
        return Scope::permits($this->scopes(), $collection, $action);
    }

    /**
     * May this store bytes of that type?
     *
     * A separate question from `permits`, and neither implies the other: being
     * allowed to write a kind of record does not say what may be kept alongside
     * it, and a resident reading a consent screen is shown the two separately
     * because they are two things to agree to.
     */
    public function permitsKeeping(string $mime): bool
    {
        return BlobScope::permits($this->scopes(), $mime);
    }
}
