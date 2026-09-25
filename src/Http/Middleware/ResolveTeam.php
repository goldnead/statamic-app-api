<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The team this request is about, from the team header.
 *
 * A named team the user is not in is a 403 `not_member`, whether the team
 * exists or not: the answer must not tell a stranger which ids are taken.
 * Without the header, statamic-teams' own rule applies
 * (`teams.current.fallback_to_current`): the user's current team, or none.
 * Either way `Teams::current()` answers with the result for the rest of the
 * request.
 *
 * Without statamic-teams installed this does nothing; endpoints that read a
 * team then see none.
 */
class ResolveTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! class_exists(Teams::class)) {
            return $next($request);
        }

        $user = Users::of($request->user());
        $value = $request->header(self::header());

        if (is_string($value) && trim($value) !== '') {
            $team = Teams::find(trim($value));

            if ($team === null || $user === null || ! $team->hasMember($user)) {
                throw ApiException::make('not_member', 403, 'team');
            }

            Teams::setCurrent($team);

            return $next($request);
        }

        $team = null;

        if ($user !== null && config('teams.current.fallback_to_current', true)) {
            $team = Teams::current($user);
        }

        Teams::setCurrent($team);

        return $next($request);
    }

    public static function header(): string
    {
        $header = config('app-api.teams.header');

        return is_string($header) && $header !== ''
            ? $header
            : (string) config('teams.current.header', 'X-Team-ID');
    }
}
