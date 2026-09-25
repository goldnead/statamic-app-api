<?php

namespace Goldnead\AppApi;

use Goldnead\AppApi\Http\ErrorRenderer;
use Goldnead\AppApi\Http\Middleware\CheckTokenAbility;
use Goldnead\AppApi\Http\Middleware\PrepareRequest;
use Goldnead\AppApi\Http\Middleware\RequireEntitlement;
use Goldnead\AppApi\Http\Middleware\RequireQuota;
use Goldnead\AppApi\Http\Middleware\RequireTwoFactorSetup;
use Goldnead\AppApi\Http\Middleware\ResolveTeam;
use Goldnead\AppApi\Integrations\ActivityRecorder;
use Goldnead\AppApi\Integrations\Automations\AutomationsBridge;
use Goldnead\AppApi\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\AppApi\Services\CheckoutStarter;
use Goldnead\AppApi\Services\ExportDownloads;
use Goldnead\AppApi\Services\Tokens;
use Goldnead\AppApi\Support\Settings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    // Registered by hand under the short `app-api` namespace, plus the JSON
    // path the Vue page's `__()` resolves through.
    protected $translations = false;

    protected $config = false;

    /**
     * Must byte-match `laravel()` in vite.config.js.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/app-api.php', 'app-api');

        $langPath = __DIR__.'/../lang';

        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('app-api', $langPath);
            $translator->addJsonPath($langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('app-api', $langPath);
            $this->app['translator']->addJsonPath($langPath);
        }

        foreach ([
            AppApiManager::class, ErrorRenderer::class, CheckoutStarter::class,
            ExportDownloads::class, Tokens::class, AutomationsBridge::class,
            WebhookManagerBridge::class, ActivityRecorder::class,
        ] as $singleton) {
            $this->app->singleton($singleton);
        }
    }

    /**
     * Settings in `boot()`, not `bootAddon()`: brand-context applies stored
     * values from an `app->booted()` callback, and `bootAddon()` runs from
     * one too, in package order.
     */
    public function boot(): void
    {
        parent::boot();

        if (class_exists(SettingsRegistry::class)) {
            $this->app->make(SettingsRegistry::class)->register(Settings::class);
        }
    }

    public function bootAddon(): void
    {
        $this
            ->bootMiddlewareAliases()
            ->bootRateLimits()
            ->bootErrors()
            ->bootApiRoutes()
            ->bootNav()
            ->bootPermissions()
            ->bootBridges()
            ->bootExportPruning()
            ->bootConfigPublishing();
    }

    /**
     * Named so as not to shadow Statamic's own `bootMiddleware()`,
     * `bootRoutes()`, `bootCommands()` and `bootPublishables()`, which the
     * addon provider runs before `bootAddon()`: a method of the same name
     * here would replace them, and the CP routes, the commands and the
     * published CP bundle would silently be gone.
     */
    protected function bootMiddlewareAliases(): self
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('app-api.entitled', RequireEntitlement::class);
        $router->aliasMiddleware('app-api.quota', RequireQuota::class);
        $router->aliasMiddleware('app-api.team', ResolveTeam::class);
        $router->aliasMiddleware('app-api.json', PrepareRequest::class);
        $router->aliasMiddleware('app-api.2fa', RequireTwoFactorSetup::class);
        $router->aliasMiddleware('app-api.ability', CheckTokenAbility::class);

        return $this;
    }

    /**
     * Read when the limiter runs, so a changed number needs no deploy.
     */
    protected function bootRateLimits(): self
    {
        $by = fn (Request $request, string $scope) => 'app-api:'.$scope.':'.($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip());

        RateLimiter::for('app-api', function (Request $request) use ($by) {
            $perMinute = (int) config('app-api.routes.rate_limit', 120);

            return $perMinute > 0 ? Limit::perMinute($perMinute)->by($by($request, 'all')) : Limit::none();
        });

        // Anything that sends a mail or builds a file.
        RateLimiter::for('app-api-mail', fn (Request $request) => Limit::perMinute(6)->by($by($request, 'mail')));

        RateLimiter::for('app-api-checkout', fn (Request $request) => Limit::perMinute(10)->by($by($request, 'checkout')));

        return $this;
    }

    /**
     * Every exception under the prefix is rendered in the one error shape.
     * Reporting is untouched: a 500 is still logged.
     */
    protected function bootErrors(): self
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (Throwable $e, $request) {
                if ($request instanceof Request && $this->isApiRequest($request)) {
                    return $this->app->make(ErrorRenderer::class)->render($e, $request);
                }

                return null;
            });
        }

        return $this;
    }

    protected function isApiRequest(Request $request): bool
    {
        if ($request->attributes->get(PrepareRequest::ATTRIBUTE) === true) {
            return true;
        }

        $prefix = trim((string) config('app-api.routes.prefix', 'api/app'), '/');

        return config('app-api.routes.enabled', true) && ($request->is($prefix) || $request->is($prefix.'/*'));
    }

    protected function bootApiRoutes(): self
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        return $this;
    }

    protected function bootNav(): self
    {
        Nav::extend(function ($nav) {
            $nav->create(__('app-api::cp.nav'))
                ->section('Tools')
                ->icon('code-block')
                ->route('app-api.index')
                ->can('view app api');
        });

        return $this;
    }

    protected function bootPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('app-api', __('app-api::cp.permission_group'), function () {
                Permission::register('view app api')
                    ->label(__('app-api::cp.permission_view'))
                    ->children([
                        Permission::make('manage app api tokens')
                            ->label(__('app-api::cp.permission_tokens')),
                    ]);

                Permission::register('manage app api settings')
                    ->label(__('app-api::cp.permission_settings'));
            });
        });

        return $this;
    }

    /**
     * From a booted callback: the siblings' bindings exist only once their
     * providers booted. Both bridges are idempotent.
     */
    protected function bootBridges(): self
    {
        $register = function (): void {
            $this->app->make(AutomationsBridge::class)->register();
            $this->app->make(WebhookManagerBridge::class)->boot($this->app['events']);
        };

        $this->app->booted(function () use ($register): void {
            $register();

            $this->app->booted($register);
        });

        $this->app->make(ActivityRecorder::class)->subscribe($this->app['events']);

        return $this;
    }

    /**
     * The commands in src/Commands are found by Statamic; only the schedule
     * is ours to add.
     */
    protected function bootExportPruning(): self
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('app-api:prune-exports')->hourly();
        });

        return $this;
    }

    protected function bootConfigPublishing(): self
    {
        $this->publishes([
            __DIR__.'/../config/app-api.php' => config_path('app-api.php'),
        ], 'app-api-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/app-api'),
        ], 'app-api-translations');

        return $this;
    }
}
