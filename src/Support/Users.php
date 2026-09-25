<?php

namespace Goldnead\AppApi\Support;

use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Statamic\Auth\User;
use Statamic\Facades\User as UserFacade;

/**
 * The signed-in person as a Statamic user, whichever guard let them in.
 *
 * The session guard hands back a Statamic user, a personal access token the
 * Eloquent model it belongs to. `User::fromUser()` makes both the same kind
 * of object, so every endpoint below speaks to one type, with file users
 * (UUID ids) and Eloquent users (integer ids) alike.
 */
class Users
{
    public static function of(?Authenticatable $authenticated): ?User
    {
        if ($authenticated === null) {
            return null;
        }

        $user = $authenticated instanceof User ? $authenticated : UserFacade::fromUser($authenticated);

        return $user instanceof User ? $user : null;
    }

    public static function current(Request $request): User
    {
        return self::of($request->user()) ?? throw ApiException::make('unauthenticated', 401);
    }

    public static function find(string|int|null $id): ?User
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = UserFacade::find($id);

        return $user instanceof User ? $user : null;
    }

    /**
     * The Eloquent model behind a user, for Sanctum's tokens and Laravel's
     * `MustVerifyEmail`. Null for a file user.
     */
    public static function model(User $user): ?object
    {
        if (method_exists($user, 'model')) {
            $model = $user->model();

            return is_object($model) ? $model : null;
        }

        return null;
    }
}
