<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Events\TokenRevoked;
use Goldnead\AppApi\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

class CpTest extends TestCase
{
    #[Test]
    public function the_page_needs_its_permission(): void
    {
        $this->actingAsCpUser('editor@example.com');

        // A page request is sent back to the CP with a message, as core does.
        $this->get(cp_route('app-api.index'))->assertRedirect();
        $this->getJson(cp_route('app-api.index'))->assertForbidden();
        $this->getJson(cp_route('app-api.openapi'))->assertForbidden();
    }

    #[Test]
    public function the_page_shows_endpoints_areas_config_and_events(): void
    {
        $this->actingAsCpUser('admin@example.com', ['view app api']);

        $this->get(cp_route('app-api.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('app-api::Overview')
                ->where('config.prefix', '/api/app')
                ->where('config.team_header', 'X-Team-ID')
                ->where('config.guard', 'sanctum')
                ->where('tokens.enabled', false)
                ->where('canManageTokens', false)
                ->has('events', 2)
                ->where('events.0.handle', 'app-api.token.created')
                ->has('areas', 7)
                ->where('areas.1.handle', 'account')
                ->where('areas.1.active', true)
                ->where('areas.5.handle', 'billing')
                ->where('areas.5.active', true)
                ->etc());
    }

    #[Test]
    public function every_endpoint_is_listed_with_whether_it_is_registered(): void
    {
        $this->actingAsCpUser('admin@example.com', ['view app api']);

        $this->get(cp_route('app-api.index'))->assertInertia(function (AssertableInertia $page) {
            $endpoints = collect($page->toArray()['props']['endpoints']);

            $login = $endpoints->firstWhere('path', '/api/app/session/login');
            $this->assertTrue($login['registered']);
            $this->assertFalse($login['auth']);

            $tokens = $endpoints->firstWhere('path', '/api/app/tokens');
            $this->assertFalse($tokens['registered']);

            $email = $endpoints->where('path', '/api/app/account/email')->firstWhere('method', 'POST');
            $this->assertTrue($email['elevated']);

            return $page;
        });
    }

    #[Test]
    public function the_full_description_is_available_in_the_cp(): void
    {
        $this->actingAsCpUser('admin@example.com', ['view app api']);

        $spec = $this->get(cp_route('app-api.openapi'))->assertOk()->json();

        // Every area, the tokens included, although they are off here.
        $this->assertArrayHasKey('/api/app/tokens', $spec['paths']);
    }

    #[Test]
    public function revoking_a_token_needs_the_token_permission(): void
    {
        Event::fake([TokenRevoked::class]);
        $this->actingAsCpUser('admin@example.com', ['view app api']);

        $this->deleteJson(cp_route('app-api.tokens.destroy', 1))->assertForbidden();
    }
}
