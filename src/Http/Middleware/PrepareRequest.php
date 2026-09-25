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
 */
class PrepareRequest
{
    public const ATTRIBUTE = 'app-api';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);
        $request->headers->set('Accept', 'application/json');
        $request->headers->remove('X-Requested-With');

        return $next($request);
    }
}
