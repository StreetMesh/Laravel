<?php

namespace StreetMesh\Server\Protocol\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One permission, at whatever age it has reached.
 *
 * A venue's request becomes a resident's approval becomes a token, and it is
 * the same thing throughout — so it is one row that grows rather than three
 * that hand off to each other. There is never a moment when it is in two
 * places, or in none.
 *
 * @property int $id
 * @property string $client_id
 * @property string|null $did
 * @property string|null $request_uri
 * @property string|null $code_hash
 * @property string|null $token_hash
 * @property string|null $refresh_hash
 * @property string|null $thumbprint
 * @property string $scope
 * @property string $redirect_uri
 * @property string|null $state
 * @property string $code_challenge
 * @property Carbon|null $approved_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $request_expires_at
 * @property Carbon|null $code_expires_at
 * @property Carbon|null $token_expires_at
 */
class Permission extends Model
{
    protected $table = 'streetmesh_permissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'request_expires_at' => 'datetime',
            'code_expires_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Withdrawal has to genuinely refuse rather than merely stop working, and
     * must not be routed around by renewing — so it is checked here, on the one
     * path everything else goes through, rather than at each place a token is
     * presented.
     */
    public function isLive(): bool
    {
        return $this->withdrawn_at === null && $this->approved_at !== null;
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return explode(' ', $this->scope);
    }

    public function permits(string $scope): bool
    {
        return $this->isLive() && in_array($scope, $this->scopes(), strict: true);
    }

    /**
     * Handed back once and never stored in the clear.
     *
     * A table of live tokens is a table of live accounts, so what is kept is
     * only enough to recognize one being presented later.
     */
    public static function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
