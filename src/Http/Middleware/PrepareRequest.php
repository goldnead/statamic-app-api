<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every request under the prefix a JSON request.
 *
 * Statamic's own controllers answer JSON when the request `wantsJson()`, and
 * several of them answer a different, older shape (status 400 with
 * `errors`/`error`) when it only looks like `ajax()`. Setting `Accept` and
 * dropping `X-Requested-With` sends every one of them down its JSON branch,
 * which is the branch this addon translates.
 *
 * As `app-api.json` on a site's own routes (mode `host`, the default) it
 * also carries the two rules that hold everywhere once somebody is signed
 * in: a token is refused while tokens are off (401 `tokens_disabled`), and
 * Statamic's enforced two-factor authentication (403
 * `two_factor_setup_required`). Put it after the auth middleware. Under the
 * prefix (mode `api`) each endpoint carries those checks itself, with the
 * session and setup endpoints left out of the second.
 */
class PrepareRequest
{
    public const ATTRIBUTE = 'app-api';

    public function handle(Request $request, Closure $next, string $mode = 'host'): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);
        $request->headers->set('Accept', 'application/json');
        $request->headers->remove('X-Requested-With');

        if ($mode === 'host' && $request->user() !== null) {
            CheckTokenAbility::refuseDisabledTokens($request);
            RequireTwoFactorSetup::check($request);
        }

        return $next($request);
    }
}
