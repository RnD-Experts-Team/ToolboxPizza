<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Replicated from pizzasys via auth.v1.user.* — this service never creates a
 * user. Still an Authenticatable because AuthTokenStoreScopeMiddleware calls
 * Auth::login() after pizzasys has already verified the token; there is no
 * password here and nothing local ever checks credentials.
 */
#[Fillable(['id', 'name', 'email', 'image_path'])]
class User extends Authenticatable
{
    /**
     * Rows arrive over NATS carrying the source service's primary key, so the
     * id is supplied, never generated.
     */
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * Nothing to cast: this table holds identity only. Verification state,
     * roles and active status all live in pizzasys, which is consulted on
     * every request rather than mirrored here.
     */
    protected function casts(): array
    {
        return [];
    }
}
