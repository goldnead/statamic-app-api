<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Auth\TwoFactor\ConfirmTwoFactorAuthentication;
use Statamic\Auth\TwoFactor\DisableTwoFactorAuthentication;
use Statamic\Auth\TwoFactor\EnableTwoFactorAuthentication;
use Statamic\Auth\TwoFactor\GenerateNewRecoveryCodes;
use Statamic\Http\Controllers\User\TwoFactorAuthenticationController;

/**
 * Setting up two-factor authentication, through Statamic's own actions
 * (`EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, …) and
 * the JSON branch of its controller. The routes carry the elevated session,
 * as core's do; a fresh login is elevated.
 *
 * With `statamic.users.two_factor_enforced_roles` these are the endpoints a
 * user without 2FA may still reach (next to session, `me` and logout); every
 * other one answers 403 `two_factor_setup_required`.
 */
class TwoFactorSetupController extends TwoFactorAuthenticationController
{
    public function start(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        if (Users::current($request)->hasEnabledTwoFactorAuthentication()) {
            throw ApiException::make('two_factor_already_enabled', 409);
        }

        return new JsonResponse($this->enable($request, $enable));
    }

    public function confirmCode(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $this->confirm($request, $confirm);

        return new JsonResponse(['recovery_codes' => Users::current($request)->twoFactorRecoveryCodes()]);
    }

    public function turnOff(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $this->disable($request, $disable);

        return new JsonResponse([
            'two_factor_setup_required' => Users::current($request)->isTwoFactorAuthenticationRequired(),
        ]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        return new JsonResponse(['recovery_codes' => Users::current($request)->twoFactorRecoveryCodes()]);
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $user = Users::current($request);
        $generate($user);

        return new JsonResponse(['recovery_codes' => $user->twoFactorRecoveryCodes()]);
    }

    protected function confirmUrl()
    {
        return route(config('app-api.routes.name', 'app-api.').'session.two-factor.confirm');
    }
}
