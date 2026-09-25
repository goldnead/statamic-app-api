<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Statamic\Http\Controllers\User\RegisterController as CoreRegisterController;
use Statamic\Http\Requests\UserRegisterRequest;

/**
 * Registration through Statamic's own controller: the user blueprint's
 * validation, `new_user_roles` and `new_user_groups`, the honeypot,
 * `UserRegistering` (a listener may refuse) and `UserRegistered` (where
 * statamic-accounts sends the confirmation mail and statamic-teams may
 * create a personal team), then the login.
 *
 * A refusal that core keeps silent on purpose (the honeypot, a listener
 * returning false) stays silent here: 201 with `user: null`, so a bot
 * learns nothing.
 */
class RegisterController extends Controller
{
    public function register(UserRegisterRequest $request, CoreRegisterController $core): JsonResponse
    {
        $response = $core($request);

        // Core returns (not throws) a ValidationException when a
        // `UserRegistering` listener throws one.
        if ($response instanceof ValidationException) {
            throw $response;
        }

        $created = true;

        if (method_exists($response, 'getContent')) {
            $body = json_decode((string) $response->getContent(), true);
            $created = (bool) (is_array($body) ? ($body['user_created'] ?? true) : true);
        }

        $user = $created ? Users::of($request->user()) : null;

        // Core signs the new user in with `Auth::login()` and keeps the
        // session id. A fresh id after a change of identity, as after a login.
        if ($user !== null && $request->hasSession()) {
            $request->session()->regenerate();
        }

        return new JsonResponse(['user' => $user === null ? null : UserResource::make($user)], 201);
    }
}
