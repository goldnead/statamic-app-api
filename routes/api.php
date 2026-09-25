<?php

use Goldnead\AppApi\Http\Middleware\PrepareRequest;
use Goldnead\AppApi\Http\Middleware\RequireElevation;
use Goldnead\AppApi\Http\Middleware\ResolveTeam;
use Goldnead\AppApi\Support\Endpoints;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Statamic\Http\Middleware\AuthGuard;

/*
 * Every endpoint, built from the one list in Support\Endpoints. The prefix,
 * the middleware and which areas exist are read here, when the routes
 * load: an area that is off, or whose addon is missing, has no routes.
 *
 * Order of the stack: the host's (Sanctum's stateful middleware by default),
 * then this addon's JSON preparation, Statamic's web guard as default guard
 * (what core's login uses), the general limiter, and per endpoint its own
 * limiter, `auth`, the elevated session and the team header.
 */

if (! config('app-api.routes.enabled', true)) {
    return;
}

$guard = (string) config('app-api.routes.guard', 'sanctum');

$stack = array_merge(
    (array) config('app-api.routes.middleware', []),
    [PrepareRequest::class, AuthGuard::class, 'throttle:app-api', SubstituteBindings::class],
);

Route::prefix(trim((string) config('app-api.routes.prefix', 'api/app'), '/'))
    ->name((string) config('app-api.routes.name', 'app-api.'))
    ->middleware($stack)
    ->group(function () use ($guard) {
        foreach (Endpoints::active() as $endpoint) {
            $middleware = [];

            if ($endpoint['throttle']) {
                $middleware[] = 'throttle:'.$endpoint['throttle'];
            }

            if ($endpoint['auth']) {
                $middleware[] = 'auth:'.$guard;
            }

            if ($endpoint['elevated']) {
                $middleware[] = RequireElevation::class;
            }

            if ($endpoint['team']) {
                $middleware[] = ResolveTeam::class;
            }

            Route::match([$endpoint['method']], $endpoint['uri'] === '' ? '/' : $endpoint['uri'], $endpoint['action'])
                ->name($endpoint['name'])
                ->middleware($middleware)
                ->where(['token' => '[A-Za-z0-9|]+', 'payment' => '[0-9]+', 'export' => '[A-Za-z0-9]+']);
        }
    });
