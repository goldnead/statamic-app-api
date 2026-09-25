# App API for Statamic

A JSON layer for single-page apps on Statamic, over [Laravel Sanctum](https://laravel.com/docs/sanctum).
A React or Vue app signs in, reads its user, its teams, its access and quotas, starts a purchase and
opens the customer portal, all as JSON under one prefix.

It translates and decides nothing itself. Login, two-factor, passkeys, registration, passwords and
the elevated session are Statamic's own controllers; account, teams, access and checkout are the
sibling addons' services. An area whose addon is not installed has no routes.

- **Session** through Statamic's auth: login (with the two-factor step), passkey login, logout,
  registration, forgotten and reset password, the current user, the elevated session.
- **Account** from [statamic-accounts](https://github.com/goldnead/statamic-accounts): address
  confirmation, change of address, deletion with grace period and blockers, data export.
- **Teams** from [statamic-teams](https://github.com/goldnead/statamic-teams): list, switch, members,
  invitations, join codes, roles, leave. The current team comes from a header.
- **Access and quotas** from [statamic-entitlements](https://github.com/goldnead/statamic-entitlements),
  for the user and the current team, plus the middlewares `app-api.entitled` (402) and
  `app-api.quota` (429) for a site's own routes.
- **Checkout** from [statamic-payments](https://github.com/goldnead/statamic-payments) and
  [statamic-offers](https://github.com/goldnead/statamic-offers): answers `{checkout_url}` instead
  of a redirect, the same request twice gives the same checkout; a link into the customer portal.
- **Personal access tokens** (optional) for clients without a browser.
- One error shape, an **OpenAPI 3.1 description** ([`openapi.json`](openapi.json)), and a Control
  Panel page listing every endpoint and what is wired.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- Laravel Sanctum 4 (a dependency of this addon)
- Optional: statamic-accounts, statamic-teams, statamic-entitlements, statamic-payments,
  statamic-offers, statamic-brand-context, statamic-automations, statamic-webhook-manager,
  statamic-activity

## Installation

```bash
composer require goldnead/statamic-app-api
php artisan vendor:publish --tag=app-api-config   # optional
```

Requires Statamic 6 and Laravel Sanctum 4 (installed with it). For personal access tokens, publish
and run Sanctum's migration (`php artisan install:api`) and add `HasApiTokens` to your user model.

### The SPA side

Serve the app from a domain listed in `sanctum.stateful` (env `SANCTUM_STATEFUL_DOMAINS`). Then:

```js
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

await axios.get('/sanctum/csrf-cookie');                       // once
const { data } = await axios.post('/api/app/session/login', { email, password });
if (data.two_factor) await axios.post('/api/app/session/two-factor', { code });
const { data: { user } } = await axios.get('/api/app/me');
```

With a token instead: `Authorization: Bearer <plain_text_token>` (no CSRF cookie needed).

## Configuration

`config/app-api.php`:

| Key | Default | |
|---|---|---|
| `routes.prefix` | `api/app` | Every endpoint lives under it. |
| `routes.middleware` | Sanctum's `EnsureFrontendRequestsAreStateful` | Runs before this addon's own. |
| `routes.guard` | `sanctum` | What "signed in" means: session or token. |
| `routes.rate_limit` | `120` | Requests per minute per user (per address for guests). |
| `routes.openapi` | `true` | Serve `{prefix}/openapi.json`. |
| `areas.session/account/teams/access/checkout` | `true` | Switch an area off. A missing addon switches it off by itself. |
| `areas.tokens` | `false` | Personal access tokens. |
| `auth.registration` | `true` | `POST session/register`. |
| `auth.password_reset_url` | `null` | The app's page for a new password; the mail links there with `?token=`. |
| `user.fields` | `[]` | Blueprint fields added to the user object. |
| `teams.header` | `null` | The team header. Null takes `teams.current.header` (`X-Team-ID`). |
| `checkout.idempotency_seconds` | `300` | How long the same request answers with the same checkout. |
| `checkout.return_url` | `null` | Where the provider sends the buyer back (a path). |
| `portal.require_verified_email` | `true` | Only a confirmed address gets a portal link. |
| `export.link_minutes` | `10` | Lifetime of an export's download link. |
| `tokens.abilities` / `tokens.expires_after_days` | `['*']` / `null` | What a token may ask for, how long it lives. |

The runtime values (rate limit, reset page, user fields, checkout, portal, export, token lifetime)
are also editable under *Settings, Addon settings* when statamic-brand-context is installed. The
route values are read when the routes load and stay in the config file.

`AppApi::transformUserUsing(fn (array $data, $user) => $data + ['plan' => …])` changes the user object.

### Example: ChoirLive

```php
// config/app-api.php
'teams' => ['header' => 'X-Tenant-ID'],
'auth' => ['password_reset_url' => '/reset-password'],
'user' => ['fields' => ['locale']],

// config/teams.php
'current' => ['fallback_to_current' => false],
```

## Endpoints

All paths below the prefix (`/api/app` by default). *Session/token*: 401 without. *Confirmation*:
Statamic's elevated session, 423 `elevation_required` without (see below). *Team header*: the team
named in the header, 403 `not_member` for a team the user is not in, whether it exists or not.

| Method | Path | Needs | |
|---|---|---|---|
| GET | `/` | | Active areas, team header, CSRF cookie path. |
| GET | `/openapi.json` | | This description. |
| POST | `/session/login` | | Statamic's login. `{two_factor: true}` when a code follows. |
| POST | `/session/two-factor` | | Code or recovery code. |
| GET | `/session/passkey/options` | | WebAuthn options. |
| POST | `/session/passkey` | | Passkey login. |
| POST | `/session/register` | | Statamic's registration, signs in. 201. |
| POST | `/session/logout` | session/token | Ends the session. 204. |
| GET | `/me` | session/token | The user. |
| POST | `/password/forgot` | | Reset mail through Statamic's broker. 202, the same for unknown addresses. |
| POST | `/password/reset` | | New password with the mailed token. |
| GET | `/session/elevation` | session/token | Elevated or not, and how this user confirms. |
| POST | `/session/elevation` | session/token | Confirm with `password`, `verification_code` or a passkey assertion. |
| POST | `/session/elevation/code` | session/token | Mail a code (accounts without a password). |
| GET | `/session/elevation/passkey-options` | session/token | WebAuthn options for confirming. |
| GET | `/account` | session/token | Confirmation, pending change, deletion, blockers. |
| POST | `/account/verification` | session/token | Send the confirmation mail again. 202. |
| POST | `/account/email` | confirmation | Change the address (confirmed by mail). 202. |
| DELETE | `/account/email` | session/token | Withdraw it. |
| POST | `/account/deletion` | confirmation | Schedule the deletion. 202, or 409 `deletion_blocked` with `details.blockers`. |
| DELETE | `/account/deletion` | session/token | Withdraw it. |
| POST | `/account/export` | confirmation | Build the export, answer a signed `download_url`. 201. |
| GET | `/account/export/{export}` | session/token | The file, once, for its owner. |
| GET | `/teams` | session/token | Teams with role, `current_team_id`. |
| POST | `/teams` | session/token | Create a team. 201. |
| GET | `/teams/current` | team header | The team of this request. |
| PUT | `/teams/current` | session/token | Switch (`team`: id or uuid). |
| POST | `/teams/join` | session/token | Join with `code`. |
| GET | `/teams/invitations/{token}` | | What an invitation is for. |
| POST | `/teams/invitations/{token}/accept` | session/token | Accept it. |
| GET | `/teams/{team}` | session/token | One team with role and permissions. |
| GET | `/teams/{team}/members` | session/token | Members. |
| PUT | `/teams/{team}/members/{user}/role` | session/token | Change a role. |
| DELETE | `/teams/{team}/members/{user}` | session/token | Remove a member. 204. |
| POST | `/teams/{team}/leave` | session/token | Leave. 204. |
| GET | `/teams/{team}/invitations` | session/token | Pending invitations. |
| POST | `/teams/{team}/invitations` | session/token | Invite (`email`, `role`). 201, with the link. |
| DELETE | `/teams/{team}/invitations/{invitation}` | session/token | Withdraw. 204. |
| POST | `/teams/{team}/join-code` | session/token | New join code. |
| GET | `/access` | team header | Products and quotas, user and team. |
| GET | `/access/products/{product}` | team header | `allowed`, with the decision for user and team. |
| GET | `/access/quotas/{key}` | team header | One quota for user and team (`?current=` for stock limits). |
| POST | `/checkout` | team header | Start a purchase (`product` or `offer`, `for: user\|team`, `confirmed: true`). 201 `{checkout_url, payment}`; the same request again 200 with `reused: true`. `Idempotency-Key` narrows it. |
| GET | `/checkout/{payment}` | session/token | State of an own checkout. |
| POST | `/portal` | session/token | Signed link into the customer portal. 403 `email_unverified` for an unconfirmed address. |
| GET | `/tokens` | session/token | Own tokens. |
| POST | `/tokens` | confirmation | New token; `plain_text_token` only in this answer. 201. |
| DELETE | `/tokens/{token}` | session/token | Revoke. 204. |

Request and response bodies: [`openapi.json`](openapi.json), or `php artisan app-api:openapi`
(`--all` for every area) to write the description of your site, e.g. for
`npx openapi-typescript openapi.json -o src/api.d.ts`.

## Errors

```json
{ "error": { "code": "not_member", "message": "You are not a member of this team.", "field": "team" } }
```

`code` is stable, `message` translated, `field` names the input, `details` carries what the client
needs to act (`blockers`, `products`, `quota`, `retry_after`, `method`).

| Status | Codes |
|---|---|
| 401 | `unauthenticated` |
| 402 | `payment_required` |
| 403 | `forbidden`, `not_member`, `invitation_wrong_email`, `join_disabled`, `impersonation_locked`, `email_unverified`, `invalid_signature` |
| 404 | `not_found`, `product_not_found`, `invitation_not_found`, `join_code_invalid`, `export_disabled`, `portal_disabled` |
| 409 | `already_verified`, `verification_disabled`, `deletion_blocked`, `account_refused`, `offer_unavailable`, `sold_out`, `tokens_unsupported` |
| 410 | `invitation_expired`, `invitation_used`, `invitation_revoked` |
| 419 | `csrf_token_mismatch` |
| 422 | `validation_failed` (with `details.errors`), `invalid_credentials`, `passkey_required`, `invalid_passkey`, `two_factor_not_started`, `reset_failed`, `email_rejected`, `consent_required`, `checkout_refused`, `team_required`, `unknown_role`, `last_owner`, `already_member`, `personal_team`, `code_unavailable`, `request_refused` |
| 423 | `elevation_required` (with `details.method`, `details.confirm_url`), `read_only` |
| 429 | `too_many_requests` (with `Retry-After`), `too_many_attempts`, `quota_exceeded` |
| 503 | `provider_unavailable` |

### Confirmation (elevated session)

With `statamic.users.elevated_sessions_enabled`, changing the address, deleting, exporting and
creating a token answer 423 until the user confirms: ask for the password (or, for
`details.method = verification_code`, `POST session/elevation/code` and ask for the mailed code),
`POST session/elevation`, repeat the request. A login elevates the session, as in Statamic.

## For a site's own routes

```php
Route::middleware(['auth:sanctum', 'app-api.json', 'app-api.team', 'app-api.entitled:pro,chor'])
    ->get('/api/scores/{score}', …);          // 402 payment_required without access

Route::middleware(['auth:sanctum', 'app-api.json', 'app-api.team', 'app-api.quota:analyses'])
    ->post('/api/analyses', …);               // 429 quota_exceeded when nothing is left
```

`app-api.json` gives the route the error shape, `app-api.team` reads the team header.

## Control Panel

*Tools, App API* (permission `view app api`): every endpoint with method, path, what it needs and
whether it is registered here; the areas and their addons; the request setup (prefix, guard,
middleware, team header, stateful domains, CSRF cookie); the error codes; the events with how many
automations and webhooks listen; the tokens, which `manage app api tokens` may revoke.

## Events

Only what this addon does itself; everything else is the sibling's event.

| Event | Handle | Payload |
|---|---|---|
| `TokenCreated` | `app-api.token.created` | `user {id, email}`, `token {id, name, abilities, expires_at}` |
| `TokenRevoked` | `app-api.token.revoked` | `user`, `token {id, name}`, `revoked_by` (`user`\|`cp`), `actor_id` |

Both are triggers in statamic-automations (group "App API") and statamic-webhook-manager, and are
written to statamic-activity. No payload carries a token.

## License

Proprietary, part of the goldnead Statamic suite. See [LICENSE.md](LICENSE.md).
