<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints that live in the session (login, two-factor, passkeys,
 * registration, the forgotten password, the elevated session) need one.
 *
 * Sanctum starts the session only for a request from one of the domains in
 * `sanctum.stateful` (by `Origin` or `Referer`). Everything else would reach
 * Statamic's controllers without a session store and end as a 500. This
 * answers 400 `stateful_origin_required` instead, naming the setting.
 */
class RequireSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw self::refusal($request);
        }

        return $next($request);
    }

    public static function refusal(Request $request): ApiException
    {
        return ApiException::make('stateful_origin_required', 400, null, array_filter([
            'config' => 'sanctum.stateful',
            'origin' => $request->headers->get('Origin') ?? $request->headers->get('Referer'),
        ]));
    }
}
