<?php

namespace Goldnead\AppApi\Support;

use Goldnead\AppApi\Http\Controllers\AccessController;
use Goldnead\AppApi\Http\Controllers\AccountController;
use Goldnead\AppApi\Http\Controllers\CheckoutController;
use Goldnead\AppApi\Http\Controllers\MetaController;
use Goldnead\AppApi\Http\Controllers\Session\ElevationController;
use Goldnead\AppApi\Http\Controllers\Session\ForgotPasswordController;
use Goldnead\AppApi\Http\Controllers\Session\LoginController;
use Goldnead\AppApi\Http\Controllers\Session\PasskeyController;
use Goldnead\AppApi\Http\Controllers\Session\RegisterController;
use Goldnead\AppApi\Http\Controllers\Session\ResetPasswordController;
use Goldnead\AppApi\Http\Controllers\Session\SessionController;
use Goldnead\AppApi\Http\Controllers\Session\TwoFactorController;
use Goldnead\AppApi\Http\Controllers\TeamController;
use Goldnead\AppApi\Http\Controllers\TokenController;
use Statamic\Facades\TwoFactor;

/**
 * The one list of endpoints.
 *
 * The routes, the endpoint table in the Control Panel and the OpenAPI
 * description are all built from here, so the three cannot disagree. Each
 * entry:
 *
 * - `area`: see {@see Areas}; an inactive area registers nothing
 * - `auth`: needs a signed-in user (401 without)
 * - `elevated`: needs Statamic's elevated session (423 without)
 * - `team`: reads the team header; a team the user is not in is a 403
 * - `throttle`: a named limiter on top of the general one
 * - `body` / `query`: the input, as `name => type` (`?` marks optional)
 * - `response`: the schema of a success, `status` its HTTP status
 * - `errors`: the error codes this endpoint answers with, besides 401
 * - `when`: an extra condition read at route load (registration, 2FA, offers)
 */
class Endpoints
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // Meta ------------------------------------------------------------
            self::make('session', 'GET', '', [MetaController::class, 'index'], 'meta', 'What this API offers: active areas, the team header, the CSRF cookie path.', response: 'Meta'),
            self::make('session', 'GET', 'openapi.json', [MetaController::class, 'openapi'], 'openapi', 'This description, as OpenAPI 3.1.', response: 'OpenApi', when: 'openapi'),

            // Session: Statamic's own login, 2FA, passkeys, registration ------
            self::make('session', 'POST', 'session/login', [LoginController::class, 'login'], 'session.login', "Sign in with Statamic's login. Answers `two_factor: true` when a code is needed next.", body: ['email' => 'string', 'password' => 'string', 'remember' => '?boolean'], response: 'LoginResult', errors: ['validation_failed', 'invalid_credentials', 'passkey_required', 'too_many_attempts'], throttle: 'statamic.auth'),
            self::make('session', 'POST', 'session/two-factor', [TwoFactorController::class, 'store'], 'session.two-factor', 'Second step of a login with two-factor authentication: a code from the app or a recovery code.', body: ['code' => '?string', 'recovery_code' => '?string'], response: 'UserEnvelope', errors: ['validation_failed', 'too_many_requests'], when: 'two_factor'),
            self::make('session', 'GET', 'session/passkey/options', [PasskeyController::class, 'options'], 'session.passkey.options', 'WebAuthn assertion options for a passkey login.', response: 'WebAuthnOptions', throttle: 'statamic.passkeys'),
            self::make('session', 'POST', 'session/passkey', [PasskeyController::class, 'login'], 'session.passkey', 'Sign in with a passkey (the WebAuthn assertion).', body: ['id' => 'string', 'rawId' => 'string', 'response' => 'object', 'type' => 'string'], response: 'UserEnvelope', errors: ['validation_failed', 'invalid_passkey'], throttle: 'statamic.passkeys'),
            self::make('session', 'POST', 'session/register', [RegisterController::class, 'register'], 'session.register', "Create an account through Statamic's registration and sign in. Fields of the user blueprint are accepted as well.", body: ['email' => 'string', 'password' => 'string', 'password_confirmation' => 'string', 'name' => '?string'], response: 'UserEnvelope', status: 201, errors: ['validation_failed', 'registration_refused'], throttle: 'statamic.auth', when: 'registration'),
            self::make('session', 'POST', 'session/logout', [SessionController::class, 'logout'], 'session.logout', 'Sign out and end the session.', status: 204, auth: true),
            self::make('session', 'GET', 'me', [SessionController::class, 'me'], 'me', 'The signed-in user.', response: 'UserEnvelope', auth: true),
            self::make('session', 'POST', 'password/forgot', [ForgotPasswordController::class, 'send'], 'password.forgot', "Mail a reset link through Statamic's password broker. Always the same answer, whether the address exists or not.", body: ['email' => 'string'], response: 'Message', status: 202, errors: ['validation_failed'], throttle: 'statamic.auth'),
            self::make('session', 'POST', 'password/reset', [ResetPasswordController::class, 'resetWithToken'], 'password.reset', 'Set a new password with the token from the mail.', body: ['token' => 'string', 'email' => 'string', 'password' => 'string', 'password_confirmation' => 'string'], response: 'Message', errors: ['validation_failed', 'reset_failed'], throttle: 'statamic.auth'),
            self::make('session', 'GET', 'session/elevation', [ElevationController::class, 'status'], 'session.elevation', "State of Statamic's elevated session and how this user confirms.", response: 'Elevation', auth: true),
            self::make('session', 'POST', 'session/elevation', [ElevationController::class, 'confirmJson'], 'session.elevation.confirm', 'Confirm with the password, the mailed code or a passkey assertion. Opens the elevated session.', body: ['password' => '?string', 'verification_code' => '?string', 'id' => '?string', 'rawId' => '?string', 'response' => '?object', 'type' => '?string'], response: 'Elevation', auth: true, errors: ['validation_failed'], throttle: 'statamic.auth', when: 'elevation'),
            self::make('session', 'POST', 'session/elevation/code', [ElevationController::class, 'sendCode'], 'session.elevation.code', 'Mail a confirmation code (accounts without a password).', status: 202, response: 'Message', auth: true, errors: ['validation_failed'], throttle: 'statamic.auth', when: 'elevation'),
            self::make('session', 'GET', 'session/elevation/passkey-options', [ElevationController::class, 'options'], 'session.elevation.passkey-options', 'WebAuthn assertion options to confirm with a passkey.', response: 'WebAuthnOptions', auth: true, throttle: 'statamic.passkeys', when: 'elevation'),

            // Account (statamic-accounts) -----------------------------------
            self::make('account', 'GET', 'account', [AccountController::class, 'show'], 'account', 'Address confirmation, pending change of address, scheduled deletion and its blockers.', response: 'Account', auth: true),
            self::make('account', 'POST', 'account/verification', [AccountController::class, 'resendVerification'], 'account.verification', 'Send the confirmation mail again.', response: 'Sent', status: 202, auth: true, errors: ['already_verified', 'verification_disabled'], throttle: 'app-api-mail'),
            self::make('account', 'POST', 'account/email', [AccountController::class, 'changeEmail'], 'account.email', 'Ask to change the address; the new one confirms by mail.', body: ['email' => 'string'], response: 'Account', status: 202, auth: true, elevated: true, errors: ['email_rejected', 'elevation_required', 'impersonation_locked'], throttle: 'app-api-mail'),
            self::make('account', 'DELETE', 'account/email', [AccountController::class, 'cancelEmailChange'], 'account.email.cancel', 'Withdraw a pending change of address.', response: 'Account', auth: true, errors: ['impersonation_locked']),
            self::make('account', 'POST', 'account/deletion', [AccountController::class, 'requestDeletion'], 'account.deletion', 'Schedule the deletion of the account after the grace period.', response: 'Account', status: 202, auth: true, elevated: true, errors: ['deletion_blocked', 'elevation_required', 'impersonation_locked'], throttle: 'app-api-mail'),
            self::make('account', 'DELETE', 'account/deletion', [AccountController::class, 'withdrawDeletion'], 'account.deletion.cancel', 'Withdraw a scheduled deletion.', response: 'Account', auth: true, errors: ['impersonation_locked']),
            self::make('account', 'POST', 'account/export', [AccountController::class, 'export'], 'account.export', 'Build the personal data export and answer with a short-lived download link.', response: 'Export', status: 201, auth: true, elevated: true, errors: ['export_disabled', 'elevation_required', 'impersonation_locked'], throttle: 'app-api-mail'),
            self::make('account', 'GET', 'account/export/{export}', [AccountController::class, 'download'], 'account.export.download', 'Download a built export (signed link from POST account/export, same user only).', response: 'File', auth: true, errors: ['not_found', 'invalid_signature']),

            // Teams (statamic-teams) ------------------------------------------
            self::make('teams', 'GET', 'teams', [TeamController::class, 'index'], 'teams', "The user's teams with their role, and which one is current.", response: 'TeamList', auth: true),
            self::make('teams', 'POST', 'teams', [TeamController::class, 'store'], 'teams.store', 'Create a team; the user becomes its owner and it becomes current.', body: ['name' => 'string'], response: 'TeamEnvelope', status: 201, auth: true, errors: ['validation_failed']),
            self::make('teams', 'GET', 'teams/current', [TeamController::class, 'current'], 'teams.current', 'The team of this request: the one in the team header, else the current one.', response: 'TeamEnvelope', auth: true, team: true, errors: ['not_member', 'team_required']),
            self::make('teams', 'PUT', 'teams/current', [TeamController::class, 'switch'], 'teams.switch', 'Make a team the current one.', body: ['team' => 'string'], response: 'TeamEnvelope', auth: true, errors: ['not_member', 'validation_failed']),
            self::make('teams', 'POST', 'teams/join', [TeamController::class, 'join'], 'teams.join', 'Join a team with its join code.', body: ['code' => 'string'], response: 'TeamEnvelope', auth: true, errors: ['join_code_invalid', 'join_disabled', 'already_member'], throttle: 'teams-join'),
            self::make('teams', 'GET', 'teams/invitations/{token}', [TeamController::class, 'invitation'], 'teams.invitations.show', 'What an invitation is for, before accepting it.', response: 'InvitationPreview', errors: ['invitation_not_found', 'invitation_expired', 'invitation_used', 'invitation_revoked']),
            self::make('teams', 'POST', 'teams/invitations/{token}/accept', [TeamController::class, 'accept'], 'teams.invitations.accept', 'Accept an invitation; the team becomes current.', response: 'TeamEnvelope', auth: true, errors: ['invitation_not_found', 'invitation_expired', 'invitation_used', 'invitation_revoked', 'invitation_wrong_email', 'already_member']),
            self::make('teams', 'GET', 'teams/{team}', [TeamController::class, 'show'], 'teams.show', 'One team, with the role and permissions of the user in it.', response: 'TeamEnvelope', auth: true, errors: ['not_member']),
            self::make('teams', 'GET', 'teams/{team}/members', [TeamController::class, 'members'], 'teams.members', 'The members of a team.', response: 'MemberList', auth: true, errors: ['not_member']),
            self::make('teams', 'PUT', 'teams/{team}/members/{user}/role', [TeamController::class, 'changeRole'], 'teams.members.role', 'Change the role of a member.', body: ['role' => 'string'], response: 'MemberEnvelope', auth: true, errors: ['not_member', 'forbidden', 'unknown_role', 'last_owner']),
            self::make('teams', 'DELETE', 'teams/{team}/members/{user}', [TeamController::class, 'removeMember'], 'teams.members.remove', 'Remove a member.', status: 204, auth: true, errors: ['not_member', 'forbidden', 'last_owner']),
            self::make('teams', 'POST', 'teams/{team}/leave', [TeamController::class, 'leave'], 'teams.leave', 'Leave a team.', status: 204, auth: true, errors: ['not_member', 'last_owner', 'personal_team']),
            self::make('teams', 'GET', 'teams/{team}/invitations', [TeamController::class, 'invitations'], 'teams.invitations', 'Pending invitations of a team.', response: 'InvitationList', auth: true, errors: ['not_member', 'forbidden']),
            self::make('teams', 'POST', 'teams/{team}/invitations', [TeamController::class, 'invite'], 'teams.invitations.store', 'Invite somebody by address; the mail goes out with the link.', body: ['email' => 'string', 'role' => '?string'], response: 'InvitationEnvelope', status: 201, auth: true, errors: ['not_member', 'forbidden', 'already_member', 'unknown_role', 'personal_team'], throttle: 'app-api-mail'),
            self::make('teams', 'DELETE', 'teams/{team}/invitations/{invitation}', [TeamController::class, 'revokeInvitation'], 'teams.invitations.revoke', 'Withdraw an invitation.', status: 204, auth: true, errors: ['not_member', 'forbidden', 'invitation_not_found']),
            self::make('teams', 'POST', 'teams/{team}/join-code', [TeamController::class, 'regenerateJoinCode'], 'teams.join-code', 'Issue a new join code; the old one stops working.', response: 'JoinCode', auth: true, errors: ['not_member', 'forbidden']),

            // Access and quotas (statamic-entitlements) ------------------------
            self::make('access', 'GET', 'access', [AccessController::class, 'index'], 'access', 'Products and quotas of the user, and of the current team.', response: 'Access', auth: true, team: true, errors: ['not_member']),
            self::make('access', 'GET', 'access/products/{product}', [AccessController::class, 'product'], 'access.product', 'Whether the user (personally or through a team) and the current team may use a product.', response: 'ProductAccess', auth: true, team: true, errors: ['not_member']),
            self::make('access', 'GET', 'access/quotas/{key}', [AccessController::class, 'quota'], 'access.quota', 'One quota: limit, used, remaining, period, for the user and the current team.', query: ['current' => '?integer'], response: 'QuotaAccess', auth: true, team: true, errors: ['not_member']),

            // Checkout and portal (statamic-payments, statamic-offers) --------
            self::make('checkout', 'POST', 'checkout', [CheckoutController::class, 'start'], 'checkout', 'Start a purchase and answer with the provider URL to send the buyer to. The same request twice answers with the same checkout.', body: ['product' => '?string', 'offer' => '?string', 'for' => '?string', 'bumps' => '?array', 'coupon' => '?string', 'pricing_option' => '?string', 'amount' => '?integer', 'country' => '?string', 'confirmed' => 'boolean', 'return_url' => '?string'], response: 'Checkout', status: 201, auth: true, team: true, errors: ['validation_failed', 'consent_required', 'not_member', 'forbidden', 'product_not_found', 'offer_unavailable', 'sold_out', 'checkout_refused', 'provider_unavailable'], throttle: 'app-api-checkout'),
            self::make('checkout', 'GET', 'checkout/{payment}', [CheckoutController::class, 'show'], 'checkout.show', 'The state of a checkout this user started (after the return from the provider).', response: 'CheckoutStatus', auth: true, errors: ['not_found']),
            self::make('checkout', 'POST', 'portal', [CheckoutController::class, 'portal'], 'portal', 'A short-lived link into the customer portal of statamic-payments for the confirmed address of the user.', response: 'PortalLink', auth: true, errors: ['email_unverified', 'portal_disabled']),

            // Tokens ----------------------------------------------------------
            self::make('tokens', 'GET', 'tokens', [TokenController::class, 'index'], 'tokens', "The user's personal access tokens (never the secret).", response: 'TokenList', auth: true, errors: ['tokens_unsupported']),
            self::make('tokens', 'POST', 'tokens', [TokenController::class, 'store'], 'tokens.store', 'Create a personal access token. The secret is in this answer and nowhere else.', body: ['name' => 'string', 'abilities' => '?array'], response: 'NewToken', status: 201, auth: true, elevated: true, errors: ['validation_failed', 'tokens_unsupported', 'elevation_required']),
            self::make('tokens', 'DELETE', 'tokens/{token}', [TokenController::class, 'destroy'], 'tokens.destroy', 'Revoke a personal access token.', status: 204, auth: true, errors: ['not_found', 'tokens_unsupported']),
        ];
    }

    /**
     * The endpoints of the active areas whose conditions hold now.
     *
     * @return list<array<string, mixed>>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), fn (array $endpoint) => Areas::active($endpoint['area']) && self::holds($endpoint['when'])));
    }

    public static function holds(?string $condition): bool
    {
        return match ($condition) {
            null => true,
            'openapi' => (bool) config('app-api.routes.openapi', true),
            'registration' => (bool) config('app-api.auth.registration', true),
            'two_factor' => class_exists('Statamic\Facades\TwoFactor') && TwoFactor::enabled(),
            'elevation' => (bool) config('statamic.users.elevated_sessions_enabled'),
            default => false,
        };
    }

    public static function path(string $uri): string
    {
        $prefix = trim((string) config('app-api.routes.prefix', 'api/app'), '/');

        return '/'.trim($prefix.'/'.$uri, '/');
    }

    /**
     * @param  array{0: class-string, 1: string}  $action
     * @param  array<string, string>  $body
     * @param  array<string, string>  $query
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    protected static function make(
        string $area,
        string $method,
        string $uri,
        array $action,
        string $name,
        string $summary,
        array $body = [],
        array $query = [],
        ?string $response = null,
        int $status = 200,
        bool $auth = false,
        bool $elevated = false,
        bool $team = false,
        array $errors = [],
        ?string $throttle = null,
        ?string $when = null,
    ): array {
        return compact('area', 'method', 'uri', 'action', 'name', 'summary', 'body', 'query', 'response', 'status', 'auth', 'elevated', 'team', 'errors', 'throttle', 'when');
    }
}
