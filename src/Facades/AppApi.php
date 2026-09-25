<?php

namespace Goldnead\AppApi\Facades;

use Goldnead\AppApi\AppApiManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AppApiManager transformUserUsing(?\Closure $transformer)
 * @method static array<string, mixed> user(\Statamic\Auth\User $user)
 * @method static bool active(string $area)
 * @method static list<array<string, mixed>> endpoints()
 * @method static array<string, mixed> openApi(bool $activeOnly = true)
 *
 * @see AppApiManager
 */
class AppApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AppApiManager::class;
    }
}
