<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\Request;
use Statamic\Facades\TwoFactor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statamic's enforced two-factor authentication, for the API.
 *
 * Core enforces `statamic.users.two_factor_enforced_roles` with
 * `RedirectIfTwoFactorSetupIncomplete`, which sits in the `statamic.web`
 * group and redirects to a setup page. The API never passes that group, so
 * without this a user who must set up 2FA could use every endpoint. Same
 * condition as core (also: not while an admin impersonates), answered as
 * 403 `two_factor_setup_required`. Session, `me`, logout and the setup
 * endpoints themselves do not carry this middleware.
 */
class RequireTwoFactorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Users::of($request->user());

        if (
            $user !== null
            && TwoFactor::enabled()
            && $user->isTwoFactorAuthenticationRequired()
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ! ($request->hasSession() && $request->session()->has('statamic_impersonated_by'))
        ) {
            throw ApiException::make('two_factor_setup_required', 403, null, [
                'setup_url' => url(trim((string) config('app-api.routes.prefix', 'api/app'), '/').'/session/two-factor/setup'),
            ]);
        }

        return $next($request);
    }
}
