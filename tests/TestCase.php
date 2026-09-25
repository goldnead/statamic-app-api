<?php

namespace Goldnead\AppApi\Tests;

use Goldnead\Accounts\Support\Schema;
use Goldnead\AppApi\ServiceProvider;
use Goldnead\AppApi\Tests\Fakes\FakeGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\SanctumServiceProvider;
use Statamic\Addons\Manifest;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * The suite runs against the real siblings (dev dependencies): accounts,
 * teams, entitlements, payments and offers boot as Statamic addons, with
 * their migrations. Only the payment provider is a stand-in
 * ({@see FakeGateway}), so no request leaves the machine.
 *
 * Users come from Statamic's file repository (UUIDs); the Eloquent case
 * (integer ids, tokens) is `EloquentUsersTest`.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected FakeGateway $gateway;

    /** Sibling addons booted with this one, by vendor directory. */
    protected const SIBLINGS = [
        'statamic-accounts',
        'statamic-teams',
        'statamic-entitlements',
        'statamic-payments',
        'statamic-offers',
    ];

    protected function getPackageProviders($app): array
    {
        $siblings = [];

        foreach (self::SIBLINGS as $directory) {
            if ($package = $this->sibling($directory)) {
                $siblings[] = $package['provider'];
            }
        }

        return [
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            SanctumServiceProvider::class,
            ...parent::getPackageProviders($app),
            ...$siblings,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Statamic boots an addon's `bootAddon()` only when it is in the
        // manifest; AddonTestCase writes this addon alone into it.
        $manifest = $app->make(Manifest::class);

        foreach (self::SIBLINGS as $directory) {
            if ($package = $this->sibling($directory)) {
                $manifest->manifest[$package['id']] = $package;
            }
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.system.multisite', false);
        $app['config']->set('statamic.users.elevated_sessions_enabled', false);
        $app['config']->set('cache.default', 'array');

        // A Statamic site's auth config: the `statamic` user provider, so the
        // web guard and the password broker speak to Statamic's users.
        $app['config']->set('auth.providers.users', ['driver' => 'statamic']);

        // Sanctum's SPA mode: requests from this host are stateful.
        $app['config']->set('sanctum.stateful', ['localhost']);

        $app['config']->set('statamic-payments.products', [
            'lifetime' => ['name' => 'Lifetime', 'amount_cent' => 7900, 'grants' => ['choirlive-pro']],
            'chortarif' => ['name' => 'Chortarif', 'amount_cent' => 7900, 'grants' => ['choirlive-team']],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-payments/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/sanctum/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        if (class_exists(Schema::class)) {
            Schema::flush();
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(__DIR__.'/__fixtures__/users/*.yaml') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    protected function sibling(string $directory): ?array
    {
        $path = __DIR__.'/../vendor/goldnead/'.$directory.'/composer.json';

        if (! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        $provider = $json['extra']['laravel']['providers'][0] ?? null;

        if (! is_string($provider) || ! class_exists($provider)) {
            return null;
        }

        $namespace = implode('\\', explode('\\', $provider, -1));

        return [
            'id' => $json['name'],
            'slug' => $json['extra']['statamic']['slug'] ?? null,
            'version' => 'dev-main',
            'namespace' => $namespace,
            'autoload' => $json['autoload']['psr-4'][$namespace.'\\'] ?? 'src',
            'provider' => $provider,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeUser(string $email = 'sina@example.com', array $data = [], string $password = 'geheim-123'): UserContract
    {
        $user = User::make()->email($email)->data(array_merge(['name' => ucfirst((string) strstr($email, '@', true))], $data));
        $user->password($password);
        $user->save();

        return $user;
    }

    /**
     * Sign in on Statamic's web guard, as the session would.
     *
     * After a request through `auth:sanctum` the default guard is Sanctum's
     * request guard, which remembers the user it resolved. Switching users
     * in a test has to start from fresh guards, or the next request still
     * sees the previous user (or nobody).
     */
    public function be(Authenticatable $user, $guard = null)
    {
        // A new person is a new session: Sanctum's AuthenticateSession would
        // otherwise find the previous person's password hash and sign out.
        if ($this->app->bound('session')) {
            $this->app['session']->flush();
        }

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return parent::be($user, $guard ?? 'web');
    }

    protected function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->guard('web')->logout();
    }

    /**
     * A JSON request the way a SPA sends it: from the site's own origin, so
     * Sanctum treats it as stateful (session cookie, CSRF).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/api/app/'.ltrim($uri, '/'), $data, array_merge([
            'Referer' => 'http://localhost/app',
            'Origin' => 'http://localhost',
        ], $headers));
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function actingAsCpUser(string $email, array $permissions = []): UserContract
    {
        $allowed = array_merge(['access cp'], $permissions);

        Gate::before(fn ($user, $ability) => in_array($ability, $allowed, true) ? true : null);

        $user = $this->makeUser($email);
        $this->actingAs($user);

        return $user;
    }

    protected function assertError(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
        $this->assertIsString($response->json('error.message'));

        return $response;
    }
}
