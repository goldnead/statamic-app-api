<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statamic's elevated session, answered as 423 `elevation_required`.
 *
 * The same rule as core's `RequireElevatedSession` (on when
 * `statamic.users.elevated_sessions_enabled`, read through core's
 * `hasElevatedSession()`), but core's exception renders itself as a 403 or
 * a redirect before any handler can translate it. `details.method` says how
 * this user confirms, `details.confirm_url` where.
 *
 * A request with a token has no session and cannot be elevated: those
 * endpoints need the session.
 */
class RequireElevation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('statamic.users.elevated_sessions_enabled')) {
            return $next($request);
        }

        if ($request->hasSession() && $request->hasElevatedSession()) {
            return $next($request);
        }

        $user = User::current();

        throw ApiException::make('elevation_required', 423, null, array_filter([
            'method' => $user && method_exists($user, 'getElevatedSessionMethod') ? $user->getElevatedSessionMethod() : null,
            'confirm_url' => url(trim((string) config('app-api.routes.prefix', 'api/app'), '/').'/session/elevation'),
            'session_required' => ! $request->hasSession() ?: null,
        ]));
    }
}
