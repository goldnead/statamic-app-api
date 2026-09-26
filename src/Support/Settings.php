<?php

namespace Goldnead\AppApi\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * The values an operator may change in the Control Panel, on the suite's
 * shared screen (Settings, Addon settings). Only loaded when
 * goldnead/statamic-brand-context is installed.
 *
 * **Not here, and why.** `routes.prefix`, `routes.middleware`,
 * `routes.guard`, `areas.*`, `auth.registration` and `routes.openapi` are
 * read while the routes load, before stored values are applied; a change
 * there would never arrive. `teams.header` is part of the contract with
 * the app's code, and the integration switches are read once at boot.
 */
class Settings implements ProvidesSettings
{
    /** Never rename: it is stored in every `brand_settings` row. */
    public static function settingsNamespace(): string
    {
        return 'app-api';
    }

    public static function settingsConfigPath(): string
    {
        return 'app-api';
    }

    public static function settingsPermission(): string
    {
        return 'manage app api settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('app-api::settings.groups.requests.title'),
                'description' => __('app-api::settings.groups.requests.description'),
                'fields' => [
                    static::field('routes.rate_limit', 'integer', ['min' => 0, 'max' => 10000]),
                    static::field('auth.password_reset_url', 'string', ['nullable' => true, 'max' => 255]),
                    static::field('user.fields', 'list'),
                ],
            ],
            [
                'title' => __('app-api::settings.groups.checkout.title'),
                'description' => __('app-api::settings.groups.checkout.description'),
                'fields' => [
                    static::field('checkout.idempotency_seconds', 'integer', ['min' => 1, 'max' => 86400]),
                    static::field('checkout.return_url', 'string', ['nullable' => true, 'max' => 255]),
                    static::field('portal.require_verified_email', 'boolean'),
                    static::field('billing.return_url', 'string', ['nullable' => true, 'max' => 255]),
                    static::field('billing.cancellation_copy_to_team', 'boolean'),
                ],
            ],
            [
                'title' => __('app-api::settings.groups.account.title'),
                'description' => __('app-api::settings.groups.account.description'),
                'fields' => [
                    static::field('export.link_minutes', 'integer', ['min' => 1, 'max' => 1440]),
                    static::field('tokens.expires_after_days', 'integer', ['nullable' => true, 'min' => 1, 'max' => 3650]),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("app-api::settings.fields.{$handle}.label"),
            'description' => __("app-api::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
