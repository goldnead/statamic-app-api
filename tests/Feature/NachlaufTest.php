<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Findings from the rollout of 0.1.0 on the demo (25.09.2026).
 */
class NachlaufTest extends TestCase
{
    #[Test]
    public function a_client_error_is_not_written_to_the_error_log(): void
    {
        Log::spy();

        // Anonymous, without an Origin from sanctum.stateful: 400.
        $this->assertError(
            $this->json('POST', '/api/app/session/register', ['email' => 'anonym@example.com', 'password' => 'Geheim-12345!', 'password_confirmation' => 'Geheim-12345!']),
            400,
            'stateful_origin_required',
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
    }

    #[Test]
    public function a_server_error_of_the_addon_is_still_reported(): void
    {
        Log::spy();

        report(ApiException::make('broken', 500));

        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function the_json_translations_do_not_rename_core_or_other_addons(): void
    {
        $own = json_decode((string) file_get_contents(__DIR__.'/../../lang/de.json'), true);
        $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

        // Names of addons in the family: a JSON key with that name renames
        // the addon everywhere, for instance in the addon list.
        foreach (['Activity', 'Events', 'Teams', 'Accounts', 'Automations', 'Webhook Manager', 'Entitlements', 'Payments'] as $name) {
            $this->assertArrayNotHasKey($name, $own, "lang/de.json must not translate the addon name \"{$name}\", use a key under app-api::");
        }

        // JSON translations are global: a key Statamic translates otherwise
        // changes Statamic's own wording.
        foreach ($own as $key => $value) {
            if (isset($core[$key])) {
                $this->assertSame($core[$key], $value, "lang/de.json overrides Statamic's \"{$key}\"");
            }
        }

        app()->setLocale('de');
        $this->assertSame('Ereignisse', __('app-api::cp.events'));
        $this->assertSame('Webhook-Manager', __('app-api::cp.webhook_manager'));
    }
}
