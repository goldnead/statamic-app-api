<?php

namespace Goldnead\AppApi\Support;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Teams\Facades\Teams;
use Statamic\Auth\User;

/**
 * Who holds access: the user and the team of this request.
 *
 * `Entitlements::allows($user, …)` already counts the grants of every team
 * the user is in (statamic-teams registers itself as a subject expander).
 * The current team is asked on its own as well, because "may this choir use
 * it" and "may this person use it anywhere" are two questions an app asks.
 */
class Subjects
{
    public static function team(): ?object
    {
        if (! class_exists(Teams::class)) {
            return null;
        }

        return Teams::current();
    }

    public static function allows(User $user, string $product): bool
    {
        if (Entitlements::allows($user, $product)) {
            return true;
        }

        $team = self::team();

        return $team !== null && Entitlements::allows($team, $product);
    }

    /**
     * The quota that counts for this request: the current team's when there
     * is one, the user's otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function quota(User $user, string $key, ?int $current = null): ?array
    {
        $team = self::team();

        return Entitlements::quota($team ?? $user, $key, $current)->toArray();
    }
}
