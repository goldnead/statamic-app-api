<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Subjects;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `app-api.quota:<key>` for a site's own routes: 429 `quota_exceeded` when
 * nothing of the quota is left, with the quota in `details`.
 *
 * Only a look, not a booking: the route itself books with
 * `Entitlements::consume()` (usage) or checks `withinLimit()` with its own
 * count (stock), because only the route knows whether its work succeeded.
 * The subject is the current team when there is one, else the user, as in
 * GET access/quotas/{key}.
 */
class RequireQuota
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $quota = Subjects::quota(Users::current($request), $key);

        if ($quota !== null && $quota['remaining'] !== null && $quota['remaining'] <= 0) {
            throw ApiException::make('quota_exceeded', 429, null, ['quota' => $quota]);
        }

        return $next($request);
    }
}
