<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a personal access token may reach: `<area>:read` for GET, and
 * `<area>:write` for everything else (`teams:read`, `checkout:write`, …),
 * or `*`. A browser session is not a token and passes.
 *
 * With `app-api.areas.tokens` off, a token is refused outright (401
 * `tokens_disabled`): switching tokens off must also switch off the ones
 * handed out before.
 */
class CheckTokenAbility
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();
        $token = is_object($user) && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if (! config('app-api.areas.tokens', false)) {
            throw ApiException::make('tokens_disabled', 401);
        }

        $ability = self::ability($area, $request->method());

        if (! $token->can($ability)) {
            throw ApiException::make('token_ability_missing', 403, null, ['ability' => $ability]);
        }

        return $next($request);
    }

    public static function ability(string $area, string $method): string
    {
        return $area.':'.(in_array(strtoupper($method), ['GET', 'HEAD'], true) ? 'read' : 'write');
    }
}
