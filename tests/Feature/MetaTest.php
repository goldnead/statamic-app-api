<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MetaTest extends TestCase
{
    #[Test]
    public function the_meta_endpoint_lists_the_active_areas_and_the_team_header(): void
    {
        $this->api('GET', '')
            ->assertOk()
            ->assertJsonPath('areas.session', true)
            ->assertJsonPath('areas.account', true)
            ->assertJsonPath('areas.teams', true)
            ->assertJsonPath('areas.access', true)
            ->assertJsonPath('areas.checkout', true)
            ->assertJsonPath('areas.tokens', false)
            ->assertJsonPath('team_header', 'X-Team-ID')
            ->assertJsonPath('csrf_cookie_url', '/sanctum/csrf-cookie')
            ->assertJsonPath('prefix', '/api/app');
    }

    #[Test]
    public function the_openapi_description_is_served_and_lists_every_active_path(): void
    {
        $response = $this->api('GET', 'openapi.json')->assertOk();

        $this->assertSame('3.1.0', $response->json('openapi'));
        $this->assertArrayHasKey('/api/app/session/login', $response->json('paths'));
        $this->assertArrayHasKey('/api/app/checkout', $response->json('paths'));
        $this->assertArrayNotHasKey('/api/app/tokens', $response->json('paths'));
        $this->assertContains('not_member', $response->json('components.schemas.Error.properties.error.properties.code.enum'));
    }

    #[Test]
    public function an_unknown_path_under_the_prefix_answers_in_the_error_shape(): void
    {
        $this->assertError($this->api('GET', 'nothing-here'), 404, 'not_found');
    }

    #[Test]
    public function an_endpoint_that_needs_a_session_answers_401_without_one(): void
    {
        $this->assertError($this->api('GET', 'me'), 401, 'unauthenticated');
    }
}
