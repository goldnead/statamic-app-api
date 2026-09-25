<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
 * 403 `two_factor_setup_required`. Under the prefix, session, `me`, logout
 * and the setup endpoints do not carry it. On a site's own routes it comes
 * with `app-api.json`, or alone as `app-api.2fa`.
 */
class RequireTwoFactorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        self::check($request);

        return $next($request);
    }

    public static function check(Request $request): void
    {
        $user = Users::of($request->user());

        if (
            $user !== null
            && TwoFactor::enabled()
            && $user->isTwoFactorAuthenticationRequired()
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ! ($request->hasSession() && $request->session()->has('statamic_impersonated_by'))
        ) {
            $route = config('app-api.routes.name', 'app-api.').'session.two-factor.setup';

            throw ApiException::make('two_factor_setup_required', 403, null, array_filter([
                'setup_url' => Route::has($route) ? route($route) : null,
            ]));
        }
    }
}
