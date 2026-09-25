<?php

namespace Goldnead\AppApi\Support;

/**
 * Which groups of endpoints this site offers.
 *
 * An area is on when its switch in `app-api.areas` is on **and** the addon
 * it translates to is installed. Read when the routes load: an area that is
 * off has no routes, so its paths answer 404 like any unknown path.
 */
class Areas
{
    /**
     * The class each area needs, by name so that nothing is autoloaded
     * before the check. `session` needs Statamic only; `tokens` needs
     * Sanctum's token model and a user model that can hold tokens (checked
     * per request, see {@see Tokens}).
     *
     * @var array<string, string|null>
     */
    public const REQUIRES = [
        'session' => null,
        'account' => 'Goldnead\Accounts\Facades\Accounts',
        'teams' => 'Goldnead\Teams\Facades\Teams',
        'access' => 'Goldnead\Entitlements\Facades\Entitlements',
        'checkout' => 'Goldnead\StatamicPayments\Support\Checkout',
        'tokens' => 'Laravel\Sanctum\PersonalAccessToken',
    ];

    /** The composer package behind each area, for the CP page. */
    public const PACKAGES = [
        'session' => 'statamic/cms',
        'account' => 'goldnead/statamic-accounts',
        'teams' => 'goldnead/statamic-teams',
        'access' => 'goldnead/statamic-entitlements',
        'checkout' => 'goldnead/statamic-payments',
        'tokens' => 'laravel/sanctum',
    ];

    public static function enabled(string $area): bool
    {
        return (bool) config("app-api.areas.{$area}", false);
    }

    public static function installed(string $area): bool
    {
        $class = self::REQUIRES[$area] ?? null;

        return $class === null || class_exists($class);
    }

    public static function active(string $area): bool
    {
        return array_key_exists($area, self::REQUIRES) && self::enabled($area) && self::installed($area);
    }

    /** Offers go through the checkout area, with statamic-offers present. */
    public static function offers(): bool
    {
        return self::active('checkout') && class_exists('Goldnead\StatamicOffers\Models\Offer');
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::REQUIRES);
    }

    /**
     * @return array<string, array{enabled: bool, installed: bool, active: bool, package: string}>
     */
    public static function status(): array
    {
        $rows = [];

        foreach (self::all() as $area) {
            $rows[$area] = [
                'enabled' => self::enabled($area),
                'installed' => self::installed($area),
                'active' => self::active($area),
                'package' => self::PACKAGES[$area],
            ];
        }

        return $rows;
    }
}
