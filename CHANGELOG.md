# Changelog

## Unreleased

### Added

- JSON layer under a configurable prefix (`api/app`), Sanctum SPA mode by default, optional personal access tokens.
- Session through Statamic's own controllers: login with the two-factor step, passkey login, logout, registration, forgotten and reset password, current user, elevated session (423 `elevation_required` and a confirmation endpoint).
- Account endpoints over statamic-accounts: confirmation mail, change of address, deletion with blockers, personal data export with a signed one-time download.
- Team endpoints over statamic-teams: list, create, switch, members, invitations, join codes, roles, leave; the current team from a configurable header.
- Access and quota endpoints over statamic-entitlements for user and current team; middlewares `app-api.entitled` (402), `app-api.quota` (429), `app-api.team`, `app-api.json`.
- Checkout over statamic-payments and statamic-offers answering `{checkout_url}`; the same request (or `Idempotency-Key`) answers with the same checkout; team as buyer; quantity limit; link into the customer portal.
- One error shape `{error: {code, message, field?, details?}}` for every answer under the prefix.
- OpenAPI 3.1 description from the endpoint list: served at `{prefix}/openapi.json`, `app-api:openapi`, shipped as `openapi.json`.
- Control Panel page "App API": endpoints, areas, request setup, error codes, wiring, tokens.
- Events `app-api.token.created` and `app-api.token.revoked`, bridged to automations, webhook-manager and activity.
- Settings on the shared settings page of statamic-brand-context.
- Enforced two-factor authentication holds for the API (403 `two_factor_setup_required`), with setup endpoints over Statamic's own actions.
- `GET checkout/terms` and `consent_version`: the consent text only for digital content, the version checked (409 `consent_changed`), `confirmed` strictly accepted, the order button label in the OpenAPI description.
- Token abilities per area (`<area>:read`/`write`); tokens refused while `areas.tokens` is off.

### Fixed

- A payment is found by address only when the address is confirmed.
- An `Idempotency-Key` reused with another body is 422 `idempotency_key_reused` instead of the first checkout.
- A session endpoint called from outside `sanctum.stateful` is 400 `stateful_origin_required`, not 500.
- New session id after registration; the elevation code uses Statamic's `send-elevated-session-code` limiter.
