<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\TwoFactor;
use Statamic\Http\Controllers\User\LoginController as CoreLoginController;
use Statamic\Http\Requests\UserLoginRequest;

/**
 * Statamic's front-end login, answering JSON.
 *
 * Everything that decides is core's and runs unchanged: the throttle
 * (five tries a minute per address and IP), the timing-safe credential
 * check, rehashing, the passkey-only rule, the two-factor challenge, the
 * elevated session and the new session id after login. This class only
 * replaces the two places core answers with a redirect.
 */
class LoginController extends CoreLoginController
{
    public function login(UserLoginRequest $request)
    {
        try {
            $response = parent::login($request);
        } catch (HttpResponseException) {
            // The one redirect core throws itself after the credentials were
            // accepted: an account that signs in with passkeys only
            // (`statamic.webauthn.allow_password_login_with_passkey` off).
            throw ApiException::make('passkey_required', 422, 'password');
        }

        if ($response instanceof JsonResponse && ($response->getData(true)['two_factor'] ?? false) === true) {
            return new JsonResponse(['two_factor' => true, 'two_factor_setup_required' => false, 'user' => null]);
        }

        $user = Users::current($request);

        return new JsonResponse([
            'two_factor' => false,
            // Statamic's enforced 2FA (`two_factor_enforced_roles`): until it is
            // set up, every endpoint but session, `me`, logout and the setup
            // answers 403 `two_factor_setup_required`.
            'two_factor_setup_required' => TwoFactor::enabled()
                && $user->isTwoFactorAuthenticationRequired()
                && ! $user->hasEnabledTwoFactorAuthentication(),
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Core throws a redirect back with the error in the session. Same
     * moment, same message, as JSON.
     */
    protected function throwFailedAuthenticationException(Request $request)
    {
        throw ApiException::make('invalid_credentials', 422, 'email');
    }
}
