<?php

namespace Goldnead\AppApi\Support;

use Goldnead\AppApi\Events\ApiEvent;
use Goldnead\AppApi\Events\TokenCreated;
use Goldnead\AppApi\Events\TokenRevoked;

/**
 * The events this addon fires itself. The automations bridge, the webhook
 * bridge, the activity log and the CP page read from here.
 *
 * Only the tokens: every other change the API makes is a sibling's event
 * (statamic-teams fires `teams.member.joined`, statamic-accounts
 * `accounts.deletion_requested`), and repeating those here would make every
 * flow fire twice.
 */
class EventCatalog
{
    /** @var list<class-string<ApiEvent>> */
    public const EVENTS = [
        TokenCreated::class,
        TokenRevoked::class,
    ];

    /**
     * @return list<array{class: class-string<ApiEvent>, handle: string, label: string, description: string}>
     */
    public static function all(): array
    {
        return array_map(function (string $class) {
            $handle = $class::handle();
            $key = str_replace('.', '_', substr($handle, strlen('app-api.')));

            return [
                'class' => $class,
                'handle' => $handle,
                'label' => (string) __("app-api::events.{$key}.label"),
                'description' => (string) __("app-api::events.{$key}.description"),
            ];
        }, self::EVENTS);
    }

    /** @return list<string> */
    public static function handles(): array
    {
        return array_map(fn (string $class) => $class::handle(), self::EVENTS);
    }
}
