<?php

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Every endpoint lives under one prefix. The prefix, the middleware and
    | the switches below are read when the routes load, so they are config
    | only and not on the settings page.
    |
    | `middleware` runs before this addon's own. The default is Sanctum's SPA
    | mode: a request from one of `sanctum.stateful` domains gets the session
    | cookie and CSRF check, every other request may carry a bearer token.
    | The SPA fetches `/sanctum/csrf-cookie` once before its first write.
    |
    | `guard` is the guard that decides "signed in". `sanctum` accepts the
    | session and, if tokens are on, a personal access token.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/app',
        'name' => 'app-api.',
        'middleware' => [
            EnsureFrontendRequestsAreStateful::class,
        ],
        'guard' => 'sanctum',

        // Requests per minute, per user (or address for guests). `null` or 0
        // switches the limiter off.
        'rate_limit' => 120,

        // Serve the OpenAPI description at {prefix}/openapi.json.
        'openapi' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Areas
    |--------------------------------------------------------------------------
    |
    | Which groups of endpoints are registered. An area whose addon is not
    | installed is not registered either, whatever it says here: its paths
    | answer 404. `session` needs nothing but Statamic.
    |
    | account  -> goldnead/statamic-accounts
    | teams    -> goldnead/statamic-teams
    | access   -> goldnead/statamic-entitlements
    | checkout -> goldnead/statamic-payments (offers: goldnead/statamic-offers)
    | tokens   -> personal access tokens, needs a user model with Sanctum's
    |             HasApiTokens (Eloquent users only)
    |
    */

    'areas' => [
        'session' => true,
        'account' => true,
        'teams' => true,
        'access' => true,
        'checkout' => true,
        'tokens' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // POST {prefix}/session/register, through Statamic's registration.
        'registration' => true,

        // The page of the app that sets a new password. The mail links there
        // with `?token=…`. A path on this site; null keeps Statamic's page.
        'password_reset_url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | The user object
    |--------------------------------------------------------------------------
    |
    | Every endpoint that answers with a user sends id, email, name, avatar,
    | super, email verification, two-factor state and the current team. Add
    | fields of the user's blueprint here, e.g. ['locale', 'voice_part'].
    | Never list a secret: whatever is named here leaves the server.
    |
    */

    'user' => [
        'fields' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | The header that names the current team, by id or uuid. Null takes
    | `teams.current.header` (X-Team-ID). ChoirLive sends `X-Tenant-ID`.
    | Without the header, `teams.current.fallback_to_current` decides whether
    | the user's current team counts.
    |
    */

    'teams' => [
        'header' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    'checkout' => [
        // A second identical request inside this window (a double click, a
        // retry after a timeout) answers with the first checkout instead of
        // starting another payment. An `Idempotency-Key` header narrows it
        // to exactly that key.
        'idempotency_seconds' => 300,

        // Where the provider sends the buyer back. Null uses the configured
        // page of statamic-payments. The client may pass `return_url`, a
        // path on this site.
        'return_url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer portal
    |--------------------------------------------------------------------------
    |
    | The portal of statamic-payments lists everything bought with an address.
    | Only a confirmed address gets a link: otherwise registering with a
    | stranger's address would open that stranger's orders.
    |
    */

    'portal' => [
        'require_verified_email' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal data export
    |--------------------------------------------------------------------------
    */

    'export' => [
        // How long the download link of an export works.
        'link_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal access tokens
    |--------------------------------------------------------------------------
    */

    'tokens' => [
        // Abilities a client may ask for. `*` is everything.
        'abilities' => ['*'],

        // Days until a new token expires. Null: never.
        'expires_after_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    */

    'integrations' => [
        'automations' => true,
        'webhook_manager' => true,
        'activity' => true,
    ],

];
