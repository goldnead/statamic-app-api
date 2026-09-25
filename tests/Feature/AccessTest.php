<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * statamic-entitlements through the API, for the user and the team of the
 * request, and the two middlewares a site puts on its own routes.
 */
class AccessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('entitlements.limits.keys', [
            'analyses' => ['label' => 'Analyses', 'period' => 'year', 'anchor' => 'calendar'],
            'arrangements' => ['label' => 'Arrangements'],
        ]);
        $app['config']->set('entitlements.limits.products', [
            'pro' => ['analyses' => 50, 'arrangements' => 10],
            'chor' => ['analyses' => 2, 'arrangements' => null],
        ]);
    }

    protected function defineRoutes($router): void
    {
        // A site's own route behind the two middlewares.
        Route::middleware(['web', 'auth', 'app-api.json', 'app-api.team', 'app-api.entitled:pro,chor'])
            ->get('/site/scores', fn () => ['ok' => true]);

        Route::middleware(['web', 'auth', 'app-api.json', 'app-api.team', 'app-api.quota:analyses'])
            ->post('/site/analyses', fn () => ['ok' => true]);
    }

    #[Test]
    public function the_access_endpoints_need_a_session(): void
    {
        $this->assertError($this->api('GET', 'access'), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'access/products/pro'), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'access/quotas/analyses'), 401, 'unauthenticated');
    }

    #[Test]
    public function the_user_sees_products_and_quotas_of_a_personal_grant(): void
    {
        $user = $this->makeUser('sina@example.com');
        Entitlements::grant($user, 'pro', 'manual');
        $this->actingAs($user);

        $this->api('GET', 'access')
            ->assertOk()
            ->assertJsonPath('user.products', ['pro'])
            ->assertJsonPath('user.quotas.analyses.limit', 50)
            ->assertJsonPath('user.quotas.analyses.remaining', 50)
            ->assertJsonPath('team', null);

        $this->api('GET', 'access/products/pro')->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('user.reason', 'ENTITLED');
        $this->api('GET', 'access/products/chor')->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('user.reason', 'NOT_ENTITLED');
    }

    #[Test]
    public function the_team_of_the_request_is_asked_on_its_own_and_its_quota_counts(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $team = Teams::create('Kammerchor', $owner);
        Teams::addMember($team, $member);
        Entitlements::grant($team, 'chor', 'manual');
        Entitlements::consume($team, 'analyses', 1);

        $this->actingAs($member);

        $this->api('GET', 'access', [], ['X-Team-ID' => (string) $team->id])
            ->assertOk()
            ->assertJsonPath('team.id', $team->id)
            ->assertJsonPath('team.products', ['chor'])
            ->assertJsonPath('team.quotas.analyses.used', 1)
            ->assertJsonPath('team.quotas.analyses.remaining', 1);

        $this->api('GET', 'access/products/chor', [], ['X-Team-ID' => (string) $team->id])
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('team.allowed', true);

        $this->api('GET', 'access/quotas/analyses', [], ['X-Team-ID' => (string) $team->id])
            ->assertOk()
            ->assertJsonPath('team.remaining', 1)
            ->assertJsonPath('team.holder', 'team:'.$team->id);
    }

    #[Test]
    public function a_foreign_team_in_the_header_is_403(): void
    {
        $team = Teams::create('Anderer Chor', $this->makeUser('owner@example.com'));
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->api('GET', 'access', [], ['X-Team-ID' => (string) $team->id]), 403, 'not_member');
        $this->assertError($this->api('GET', 'access/products/chor', [], ['X-Team-ID' => (string) $team->id]), 403, 'not_member');
        $this->assertError($this->api('GET', 'access/quotas/analyses', [], ['X-Team-ID' => (string) $team->id]), 403, 'not_member');
    }

    #[Test]
    public function the_entitled_middleware_answers_402_with_the_products(): void
    {
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);

        $this->assertError($this->getJson('/site/scores'), 402, 'payment_required')
            ->assertJsonPath('error.details.products', ['pro', 'chor']);

        Entitlements::grant($user, 'pro', 'manual');

        $this->getJson('/site/scores')->assertOk();
    }

    #[Test]
    public function the_entitled_middleware_counts_the_current_team(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        Entitlements::grant($team, 'chor', 'manual');
        $this->actingAs($owner);

        $this->getJson('/site/scores', ['X-Team-ID' => (string) $team->id])->assertOk();
    }

    #[Test]
    public function the_quota_middleware_answers_429_when_nothing_is_left(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        Entitlements::grant($team, 'chor', 'manual');
        $this->actingAs($owner);

        $this->postJson('/site/analyses', [], ['X-Team-ID' => (string) $team->id])->assertOk();

        Entitlements::consume($team, 'analyses', 2);

        $this->assertError($this->postJson('/site/analyses', [], ['X-Team-ID' => (string) $team->id]), 429, 'quota_exceeded')
            ->assertJsonPath('error.details.quota.remaining', 0);
    }
}
