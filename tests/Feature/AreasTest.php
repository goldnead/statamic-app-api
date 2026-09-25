<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Support\Areas;
use Goldnead\AppApi\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * An area that is off, or whose addon is missing, registers no routes: its
 * paths answer 404 like any unknown path. The switch stands in for the
 * missing addon here (a class cannot be unloaded in a test run); both go
 * through `Areas::active()`.
 */
class AreasTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app-api.areas.teams', false);
        $app['config']->set('app-api.areas.checkout', false);
        $app['config']->set('app-api.areas.tokens', true);
        $app['config']->set('app-api.auth.registration', false);
    }

    #[Test]
    public function a_switched_off_area_has_no_routes_and_answers_404(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertFalse(Route::has('app-api.teams'));
        $this->assertFalse(Route::has('app-api.checkout'));
        $this->assertTrue(Route::has('app-api.account'));

        $this->assertError($this->api('GET', 'teams'), 404, 'not_found');
        $this->assertError($this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true]), 404, 'not_found');
        $this->assertError($this->api('POST', 'session/register', ['email' => 'x@example.com']), 404, 'not_found');

        $this->api('GET', '')->assertJsonPath('areas.teams', false)->assertJsonPath('areas.checkout', false)->assertJsonPath('registration', false);
    }

    #[Test]
    public function an_area_whose_addon_is_missing_is_never_active(): void
    {
        $this->assertTrue(Areas::installed('session'));
        $this->assertTrue(Areas::installed('teams'));
        $this->assertSame('Goldnead\Teams\Facades\Teams', Areas::REQUIRES['teams']);
        $this->assertFalse(Areas::active('unknown'));
    }

    #[Test]
    public function a_file_user_cannot_hold_tokens(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->api('GET', 'tokens'), 409, 'tokens_unsupported');
        $this->assertError($this->api('POST', 'tokens', ['name' => 'x']), 409, 'tokens_unsupported');
    }

    #[Test]
    public function the_session_endpoints_stay_whatever_the_other_areas_say(): void
    {
        $this->makeUser('sina@example.com');

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])->assertOk();
    }
}
