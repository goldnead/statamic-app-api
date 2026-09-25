<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use Statamic\Notifications\PasswordReset;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\TwoFactor\TwoFactorAuthenticationProvider;
use Statamic\Facades\User;

/**
 * Login, 2FA, logout, registration and passwords go through Statamic's own
 * controllers; these tests hold the JSON the API makes of them.
 */
class SessionTest extends TestCase
{
    #[Test]
    public function login_answers_with_the_user_and_starts_a_session(): void
    {
        $user = $this->makeUser('sina@example.com');

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])
            ->assertOk()
            ->assertJsonPath('two_factor', false)
            ->assertJsonPath('user.id', $user->id())
            ->assertJsonPath('user.email', 'sina@example.com')
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user);
        $this->api('GET', 'me')->assertOk()->assertJsonPath('user.email', 'sina@example.com');
    }

    #[Test]
    public function a_wrong_password_is_422_invalid_credentials(): void
    {
        $this->makeUser('sina@example.com');

        $this->assertError($this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'falsch']), 422, 'invalid_credentials')
            ->assertJsonPath('error.field', 'email');

        $this->assertGuest();
    }

    #[Test]
    public function missing_fields_are_422_validation_failed(): void
    {
        $this->assertError($this->api('POST', 'session/login', ['email' => 'sina@example.com']), 422, 'validation_failed')
            ->assertJsonPath('error.field', 'password');
    }

    #[Test]
    public function statamics_login_limiter_answers_429_with_retry_after(): void
    {
        $this->makeUser('sina@example.com');

        // `statamic.auth`: four a minute per address.
        foreach (range(1, 4) as $ignored) {
            $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'falsch'])->assertStatus(422);
        }

        $this->assertError($this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'falsch']), 429, 'too_many_requests')
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['error' => ['details' => ['retry_after']]]);
    }

    #[Test]
    public function with_two_factor_the_login_asks_for_the_code_and_the_code_signs_in(): void
    {
        $user = $this->makeUser('sina@example.com');
        $user->set('two_factor_secret', encrypt('SECRETSECRETSECR'));
        $user->set('two_factor_confirmed_at', now()->timestamp);
        $user->save();

        $this->app->instance(TwoFactorAuthenticationProvider::class, new class implements TwoFactorAuthenticationProvider
        {
            public function generateSecretKey(int $secretLength = 16): string
            {
                return 'x';
            }

            public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string
            {
                return 'x';
            }

            public function verify(string $secret, string $code): bool
            {
                return $code === '123456';
            }
        });

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])
            ->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonPath('user', null);

        $this->assertGuest();

        $this->assertError($this->api('POST', 'session/two-factor', ['code' => '000000']), 422, 'validation_failed')
            ->assertJsonPath('error.field', 'code');

        $this->api('POST', 'session/two-factor', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('user.email', 'sina@example.com')
            ->assertJsonPath('user.two_factor_enabled', true);

        $this->assertAuthenticatedAs(User::find($user->id()));
    }

    #[Test]
    public function the_two_factor_step_without_a_login_before_it_is_refused(): void
    {
        $this->assertError($this->api('POST', 'session/two-factor', ['code' => '123456']), 422, 'two_factor_not_started');
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->api('POST', 'session/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    #[Test]
    public function logout_without_a_session_is_401(): void
    {
        $this->assertError($this->api('POST', 'session/logout'), 401, 'unauthenticated');
    }

    #[Test]
    public function registration_creates_the_user_through_statamic_and_signs_in(): void
    {
        $this->api('POST', 'session/register', [
            'email' => 'neu@example.com',
            'password' => 'ein-langes-passwort-1',
            'password_confirmation' => 'ein-langes-passwort-1',
            'name' => 'Neu',
        ])->assertCreated()->assertJsonPath('user.email', 'neu@example.com');

        $this->assertNotNull(User::findByEmail('neu@example.com'));
        $this->assertAuthenticated();
    }

    #[Test]
    public function registration_with_a_taken_address_is_422(): void
    {
        $this->makeUser('sina@example.com');

        $this->assertError($this->api('POST', 'session/register', [
            'email' => 'sina@example.com',
            'password' => 'ein-langes-passwort-1',
            'password_confirmation' => 'ein-langes-passwort-1',
        ]), 422, 'validation_failed')->assertJsonPath('error.field', 'email');
    }

    #[Test]
    public function forgot_password_sends_the_core_mail_and_says_the_same_for_unknown_addresses(): void
    {
        Notification::fake();
        config(['app-api.auth.password_reset_url' => '/passwort-neu']);
        $user = $this->makeUser('sina@example.com');

        $this->api('POST', 'password/forgot', ['email' => 'sina@example.com'])->assertStatus(202);
        $this->api('POST', 'password/forgot', ['email' => 'niemand@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, PasswordReset::class, function (PasswordReset $notification) use ($user) {
            // The link points to the app's page, with core's token.
            return str_starts_with((string) $notification->toMail($user)->actionUrl, 'http://localhost/passwort-neu?token=');
        });
        Notification::assertCount(1);
    }

    #[Test]
    public function reset_password_with_the_broker_token_sets_the_new_password(): void
    {
        $user = $this->makeUser('sina@example.com');
        $token = Password::broker(config('statamic.users.passwords.resets', 'users'))->createToken($user);

        $this->api('POST', 'password/reset', [
            'token' => $token,
            'email' => 'sina@example.com',
            'password' => 'ganz-neues-passwort-2',
            'password_confirmation' => 'ganz-neues-passwort-2',
        ])->assertOk();

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'ganz-neues-passwort-2'])->assertOk();
    }

    #[Test]
    public function reset_password_with_a_wrong_token_is_422_reset_failed(): void
    {
        $this->makeUser('sina@example.com');

        $this->assertError($this->api('POST', 'password/reset', [
            'token' => 'falsch',
            'email' => 'sina@example.com',
            'password' => 'ganz-neues-passwort-2',
            'password_confirmation' => 'ganz-neues-passwort-2',
        ]), 422, 'reset_failed');
    }

    #[Test]
    public function the_user_object_carries_the_configured_fields_and_never_a_secret(): void
    {
        config(['app-api.user.fields' => ['locale', 'password', 'two_factor_secret']]);
        $this->actingAs($this->makeUser('sina@example.com', ['locale' => 'de']));

        $response = $this->api('GET', 'me')->assertOk()->assertJsonPath('user.locale', 'de');

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('two_factor_secret', $response->json('user'));
    }
}
