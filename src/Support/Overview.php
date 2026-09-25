<?php

namespace Goldnead\AppApi\Support;

use Goldnead\AppApi\Http\Middleware\ResolveTeam;
use Goldnead\AppApi\Integrations\ActivityRecorder;
use Goldnead\AppApi\Integrations\Automations\AutomationsBridge;
use Goldnead\AppApi\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\AppApi\Services\Tokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Statamic\Auth\User;
use Throwable;

/**
 * What the CP page "App API" shows: the endpoints as they are registered
 * on this site, which areas are on and why, the settings that shape a
 * request, the events and who listens to them, and the tokens.
 *
 * The listener counts are read from the siblings' tables, not through
 * their classes: the page must render with either sibling missing.
 */
class Overview
{
    public function __construct(
        protected AutomationsBridge $automations,
        protected Tokens $tokens,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'areas' => $this->areas(),
            'endpoints' => $this->endpoints(),
            'config' => $this->config(),
            'events' => $this->events(),
            'integrations' => $this->integrations(),
            'tokens' => $this->tokenState(),
            'errors' => ErrorCodes::STATUS,
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function areas(): array
    {
        $rows = [];

        foreach (Areas::status() as $area => $status) {
            $count = count(array_filter(Endpoints::all(), fn (array $e) => $e['area'] === $area));

            $rows[] = $status + [
                'handle' => $area,
                'label' => (string) __("app-api::cp.areas.{$area}"),
                'endpoints' => $count,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    protected function endpoints(): array
    {
        $prefix = trim((string) config('app-api.routes.name', 'app-api.'));

        return array_map(fn (array $e) => [
            'area' => $e['area'],
            'method' => $e['method'],
            'path' => Endpoints::path((string) $e['uri']),
            'summary' => $e['summary'],
            'auth' => $e['auth'],
            'elevated' => $e['elevated'],
            'team' => $e['team'],
            'status' => $e['status'],
            'errors' => $e['errors'],
            'registered' => Route::has($prefix.$e['name']),
        ], Endpoints::all());
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $middleware = array_map(
            fn ($entry) => is_string($entry) ? class_basename($entry) : get_debug_type($entry),
            (array) config('app-api.routes.middleware', []),
        );

        return [
            'enabled' => (bool) config('app-api.routes.enabled', true),
            'prefix' => Endpoints::path(''),
            'guard' => (string) config('app-api.routes.guard', 'sanctum'),
            'middleware' => array_values($middleware),
            'rate_limit' => (int) config('app-api.routes.rate_limit', 120),
            'team_header' => ResolveTeam::header(),
            'team_fallback' => (bool) config('teams.current.fallback_to_current', true),
            'stateful_domains' => array_values(array_filter((array) config('sanctum.stateful', []), 'is_string')),
            'csrf_cookie_url' => Route::has('sanctum.csrf-cookie') ? route('sanctum.csrf-cookie', [], false) : null,
            'openapi_url' => Endpoints::holds('openapi') && Route::has(config('app-api.routes.name', 'app-api.').'openapi')
                ? url(Endpoints::path('openapi.json'))
                : null,
            'two_factor' => Endpoints::holds('two_factor'),
            'elevated_sessions' => Endpoints::holds('elevation'),
            'registration' => Endpoints::holds('registration'),
            'settings_url' => $this->cpRoute('brand-context.settings.index'),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function events(): array
    {
        $flows = $this->automationCounts();
        $hooks = $this->webhookCounts();

        return array_map(fn (array $event) => [
            'handle' => $event['handle'],
            'label' => $event['label'],
            'description' => $event['description'],
            'mail' => null,
            'automations' => $flows[$event['handle']] ?? 0,
            'webhooks' => $hooks[$event['handle']] ?? 0,
        ], EventCatalog::all());
    }

    /** @return array<string, array<string, mixed>> */
    protected function integrations(): array
    {
        return [
            'automations' => [
                'installed' => $this->automations->available(),
                'url' => $this->cpRoute('statamic-automations.automations.index'),
            ],
            'webhook_manager' => [
                'installed' => WebhookManagerBridge::available(),
                'url' => $this->cpRoute('webhook-manager.outbound.index'),
            ],
            'activity' => [
                'installed' => app(ActivityRecorder::class)->available(),
                'url' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function tokenState(): array
    {
        $enabled = Areas::active('tokens');
        $table = $this->tokens->tableExists();
        $rows = [];

        if ($enabled && $table) {
            $rows = $this->tokens->all()->map(function (PersonalAccessToken $token) {
                $owner = Users::of($token->tokenable instanceof \Illuminate\Contracts\Auth\Authenticatable ? $token->tokenable : null);

                return Tokens::present($token) + [
                    'user' => $owner instanceof User ? [
                        'id' => $owner->id(),
                        'email' => $owner->email(),
                        'name' => $owner->name(),
                        'edit_url' => $owner->editUrl(),
                    ] : null,
                    'revoke_url' => cp_route('app-api.tokens.destroy', $token->getKey()),
                ];
            })->values()->all();
        }

        return [
            'enabled' => $enabled,
            'table' => $table,
            'rows' => $rows,
        ];
    }

    /** @return array<string, int> */
    protected function automationCounts(): array
    {
        if (! $this->automations->available()) {
            return [];
        }

        return $this->safely(fn () => DB::table('automation_nodes')
            ->join('automations', 'automations.id', '=', 'automation_nodes.automation_id')
            ->where('automations.enabled', true)
            ->where('automation_nodes.disabled', false)
            ->whereIn('automation_nodes.type', EventCatalog::handles())
            ->groupBy('automation_nodes.type')
            ->selectRaw('automation_nodes.type as handle, count(distinct automations.id) as total')
            ->pluck('total', 'handle')
            ->map(fn ($total) => (int) $total)
            ->all(), ['automation_nodes', 'automations']);
    }

    /** @return array<string, int> */
    protected function webhookCounts(): array
    {
        if (! WebhookManagerBridge::available()) {
            return [];
        }

        return $this->safely(fn () => DB::table('webhook_outbounds')
            ->where('enabled', true)
            ->whereIn('trigger_type', EventCatalog::handles())
            ->groupBy('trigger_type')
            ->selectRaw('trigger_type as handle, count(*) as total')
            ->pluck('total', 'handle')
            ->map(fn ($total) => (int) $total)
            ->all(), ['webhook_outbounds']);
    }

    /**
     * @param  callable(): array<string, int>  $query
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    protected function safely(callable $query, array $tables): array
    {
        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    return [];
                }
            }

            return $query();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    protected function cpRoute(string $name): ?string
    {
        $full = 'statamic.cp.'.$name;

        return Route::has($full) ? route($full) : null;
    }
}
