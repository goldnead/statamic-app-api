<?php

namespace Goldnead\AppApi\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The shape of ChoirLive's user: an Eloquent model with integer ids, the
 * columns of Laravel's default users table, and Sanctum's tokens.
 */
class EloquentUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'preferences' => 'array',
    ];
}
