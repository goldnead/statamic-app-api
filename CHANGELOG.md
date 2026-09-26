# Changelog

## Unreleased

### Fixed

- A cancelled subscription whose paid term still runs said `ended_label` „Beendet am <day of the
  cancellation>" next to „Bezahlt bis <end of term>". It now says „Gekündigt, läuft bis <end of
  term>" while the term runs and „Beendet am <end of term>" after it, the same sentence as the
  portal (`Portal\Display::ending()`, statamic-payments 1.29.2). New field `ends_at`.

### Changed

- `POST billing/subscriptions/{subscription}/cancel` runs payments' own sequence,
  `Support\Cancellations::cancel()`, instead of a copy of it. Same answers and mail; the log entry
  no longer carries `via: app-api`. Requires statamic-payments ≥ 1.29.2.

### Added

- A team's agreement: the cancellation confirmation also goes, as a mail of its own, to the team's
  billing address (statamic-teams `billing.email`) when there is one and it is not the address of
  the person who cancelled. Response field `copied_to`. Setting `billing.cancellation_copy_to_team`
  (default on, in the CP under Checkout).

## 0.2.0 — 2026-09-26

### Added

- Area `billing`, the customer area of statamic-payments as JSON (token abilities `billing:read` and `billing:write`, team through the team header):
  - `GET billing`: paid orders and subscriptions of the user, and of the current team with `view billing`, with the links to cancelling without login (§ 312k BGB) and to the withdrawal function (§ 356a BGB), the display time zone and the form of address of statamic-payments.
  - `GET billing/payments/{payment}`, `GET billing/documents`, `GET billing/documents/{document}`: one order with lines, invoices and credit notes from statamic-invoices, the PDF as attachment.
  - `GET billing/subscriptions(/{subscription})`: status, paid until, next charge, price, rhythm, running coupon, masked payment method, open actions.
  - `GET|POST billing/subscriptions/{subscription}/cancel`: the confirmation page's content, then the cancellation through `Subscriptions::cancel()` with the confirmation mail and the log entry of the portal. No elevated session.
  - Pause, resume, switch plan and a new payment method (the provider's URL), as the portal offers them.
- New subscriptions carry `app_api_user_id` from their first payment (`Subscriptions::inheritMeta()`).
- Setting `billing.return_url`.

### Changed

- Needs statamic-payments 1.29 or later for the customer area (without it the area stays off by itself).

## 0.1.1 — 2026-09-25

### Fixed

- A 4xx answer of the addon (for instance 400 `stateful_origin_required` on an anonymous request) is no longer written to the error log; 5xx still are.
- `lang/de.json` no longer translates "Events", "Activity", "Automations" and "Webhook Manager" globally (it renamed the Events addon to "Ereignisse" in the addon list), and no longer overrides Statamic's "User" and "None". The CP page uses keys under `app-api::cp`.

## 0.1.0 — 2026-09-25

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
- On a site's own routes: `app-api.json` applies the enforced two-factor rule and the tokens switch; new aliases `app-api.2fa` and `app-api.ability:<area>`.
- `consent_version` is `<source version>+<hash of the wording shown>`, so a changed wording under an unchanged offer version is caught.

### Fixed

- A payment is found by address only when the address is confirmed.
- An `Idempotency-Key` reused with another body is 422 `idempotency_key_reused` instead of the first checkout.
- A session endpoint called from outside `sanctum.stateful` is 400 `stateful_origin_required`, not 500.
- New session id after registration; the elevation code uses Statamic's `send-elevated-session-code` limiter.
