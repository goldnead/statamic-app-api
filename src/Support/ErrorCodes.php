<?php

namespace Goldnead\AppApi\Support;

/**
 * Every error code this API answers with, and its status.
 *
 * Public contract: a client switches on `error.code`. Codes that come from
 * statamic-teams keep the name that addon gives them (`TeamsException`
 * reasons). The README table and the OpenAPI description are built from
 * this list.
 */
class ErrorCodes
{
    /** @var array<string, int> */
    public const STATUS = [
        // General
        'unauthenticated' => 401,
        'forbidden' => 403,
        'not_found' => 404,
        'validation_failed' => 422,
        'csrf_token_mismatch' => 419,
        'too_many_requests' => 429,
        'server_error' => 500,
        'invalid_signature' => 403,
        'request_refused' => 422,

        // Session
        'invalid_credentials' => 422,
        'passkey_required' => 422,
        'invalid_passkey' => 422,
        'too_many_attempts' => 429,
        'two_factor_not_started' => 422,
        'registration_refused' => 422,
        'reset_failed' => 422,
        'elevation_required' => 423,
        'code_unavailable' => 422,

        // Account
        'already_verified' => 409,
        'verification_disabled' => 409,
        'email_rejected' => 422,
        'impersonation_locked' => 403,
        'deletion_blocked' => 409,
        'account_refused' => 409,
        'export_disabled' => 404,
        'email_unverified' => 403,

        // Teams (statamic-teams)
        'not_member' => 403,
        'already_member' => 422,
        'invitation_not_found' => 404,
        'invitation_expired' => 410,
        'invitation_used' => 410,
        'invitation_revoked' => 410,
        'invitation_wrong_email' => 403,
        'join_code_invalid' => 404,
        'join_disabled' => 403,
        'unknown_role' => 422,
        'last_owner' => 422,
        'personal_team' => 422,
        'already_owner' => 422,
        'team_mismatch' => 422,
        'team_required' => 422,
        'read_only' => 423,

        // Access
        'payment_required' => 402,
        'quota_exceeded' => 429,

        // Checkout
        'consent_required' => 422,
        'product_not_found' => 404,
        'offer_unavailable' => 409,
        'sold_out' => 409,
        'checkout_refused' => 422,
        'provider_unavailable' => 503,
        'portal_disabled' => 404,

        // Tokens
        'tokens_unsupported' => 409,
    ];

    public static function status(string $code): int
    {
        return self::STATUS[$code] ?? 422;
    }
}
