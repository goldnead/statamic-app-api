<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Statamic\Http\Controllers\TwoFactorChallengeController;
use Statamic\Http\Requests\TwoFactorChallengeRequest;

/**
 * The second step of a login, through Statamic's two-factor challenge.
 *
 * Core checks the code or the recovery code (a used recovery code is
 * replaced), signs in, elevates and renews the session. Core's constructor
 * also adds the Inertia page middleware and a redirect for signed-in users;
 * neither belongs on a JSON endpoint, so only its throttle is kept.
 */
class TwoFactorController extends TwoFactorChallengeController
{
    public function __construct()
    {
        $this->middleware('throttle:two-factor');
    }

    public function store(TwoFactorChallengeRequest $request)
    {
        if (! $request->hasChallengedUser()) {
            throw ApiException::make('two_factor_not_started', 422);
        }

        parent::store($request);

        return new JsonResponse(['user' => UserResource::make(Users::current($request))]);
    }
}
