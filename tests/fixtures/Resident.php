<?php

namespace StreetMesh\Server\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Stands in for the host application's user model.
 *
 * This package ships no such model, and deliberately: whose accounts these are
 * is the application's business. What is decided here is what happens when one
 * of them is given an address.
 *
 * Both halves of a server need it, for opposite reasons. A domicile joins an
 * account to an identity. A venue has no accounts at all — and needs this
 * anyway, because one server can be both, and the door behaves differently for
 * somebody the other half already knows.
 */
class Resident extends User
{
    protected $table = 'users';

    protected $guarded = [];
}
