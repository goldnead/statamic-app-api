<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Statamic's elevated session, on. The confirmation endpoints exist only
 * then (read when the routes load), so the switch is set before boot.
 */
class ElevationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic.users.elevated_sessions_enabled', true);
    }

    #[Test]
    public function a_sensitive_action_without_elevation_is_423_with_the_method(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->api('POST', 'account/email', ['email' => 'neu@example.com']), 423, 'elevation_required')
            ->assertJsonPath('error.details.method', 'password_confirmation')
            ->assertJsonPath('error.details.confirm_url', 'http://localhost/api/app/session/elevation');

        $this->assertError($this->api('POST', 'account/deletion'), 423, 'elevation_required');
        $this->assertError($this->api('POST', 'account/export'), 423, 'elevation_required');
    }

    #[Test]
    public function confirming_with_the_password_opens_the_elevated_session(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->api('GET', 'session/elevation')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('elevated', false)
            ->assertJsonPath('method', 'password_confirmation');

        $this->assertError($this->api('POST', 'session/elevation', ['password' => 'falsch']), 422, 'validation_failed')
            ->assertJsonPath('error.field', 'password');

        $this->api('POST', 'session/elevation', ['password' => 'geheim-123'])
            ->assertOk()
            ->assertJsonPath('elevated', true);

        $this->api('POST', 'account/email', ['email' => 'neu@example.com'])->assertStatus(202);
    }

    #[Test]
    public function the_elevation_endpoints_need_a_session(): void
    {
        $this->assertError($this->api('GET', 'session/elevation'), 401, 'unauthenticated');
        $this->assertError($this->api('POST', 'session/elevation', ['password' => 'x']), 401, 'unauthenticated');
    }

    #[Test]
    public function a_login_elevates_the_session_as_core_does(): void
    {
        Mail::fake();
        $this->makeUser('sina@example.com');

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])->assertOk();

        $this->api('GET', 'session/elevation')->assertJsonPath('elevated', true);
        $this->api('POST', 'account/email', ['email' => 'neu@example.com'])->assertStatus(202);
    }

    #[Test]
    public function a_code_is_only_sent_to_accounts_without_a_password(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->api('POST', 'session/elevation/code'), 422, 'code_unavailable');
    }
}
