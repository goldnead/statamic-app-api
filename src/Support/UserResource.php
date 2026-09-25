<?php

namespace Goldnead\AppApi\Support;

use Closure;
use Goldnead\Accounts\Facades\Accounts;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Statamic\Auth\User;

/**
 * What the API says about a user.
 *
 * A fixed set of fields (no password, no two-factor secret, no remember
 * token), plus the blueprint fields a site names in `app-api.user.fields`,
 * plus whatever a site adds with `AppApi::transformUserUsing()`.
 */
class UserResource
{
    protected static ?Closure $transformer = null;

    /** @param  (Closure(array<string, mixed>, User): array<string, mixed>)|null  $transformer */
    public static function transformUsing(?Closure $transformer): void
    {
        static::$transformer = $transformer;
    }

    /** @return array<string, mixed> */
    public static function make(User $user): array
    {
        $verifiedAt = self::verifiedAt($user);

        $data = [
            'id' => $user->id(),
            'email' => $user->email(),
            'name' => $user->name(),
            'initials' => $user->initials(),
            'avatar' => $user->avatar(),
            'super' => $user->isSuper(),
            'email_verified' => $verifiedAt === false ? null : $verifiedAt !== null,
            'email_verified_at' => is_string($verifiedAt) && $verifiedAt !== '' ? $verifiedAt : null,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'current_team_id' => self::currentTeamId($user),
        ];

        foreach ((array) config('app-api.user.fields', []) as $field) {
            if (is_string($field) && $field !== '' && ! array_key_exists($field, $data) && ! self::secret($field)) {
                $data[$field] = $user->get($field);
            }
        }

        return static::$transformer ? (static::$transformer)($data, $user) : $data;
    }

    /**
     * An ISO date when confirmed, null when not, false when nothing on this
     * site can tell (no statamic-accounts and no `MustVerifyEmail`).
     */
    protected static function verifiedAt(User $user): string|false|null
    {
        if (class_exists(Accounts::class)) {
            $verification = Accounts::verification();

            if ($verification->enabled()) {
                return $verification->verifiedAt($user)?->toIso8601String()
                    ?? ($verification->isVerified($user) ? '' : null);
            }
        }

        $model = Users::model($user);

        if ($model instanceof MustVerifyEmail) {
            if (! $model->hasVerifiedEmail()) {
                return null;
            }

            $at = method_exists($model, 'getAttribute') ? $model->getAttribute('email_verified_at') : null;

            return $at instanceof \DateTimeInterface ? $at->format(DATE_ATOM) : '';
        }

        return false;
    }

    /** Whether the user's address is confirmed; null when nothing can tell. */
    public static function verified(User $user): ?bool
    {
        $at = self::verifiedAt($user);

        return $at === false ? null : $at !== null;
    }

    protected static function currentTeamId(User $user): ?int
    {
        if (! Areas::active('teams')) {
            return null;
        }

        $team = Teams::current($user);

        return $team === null ? null : (int) $team->getKey();
    }

    protected static function secret(string $field): bool
    {
        return in_array(strtolower($field), [
            'password', 'password_hash', 'remember_token', 'two_factor_secret',
            'two_factor_recovery_codes', 'two_factor_confirmed_at', 'passkeys',
        ], true);
    }
}
