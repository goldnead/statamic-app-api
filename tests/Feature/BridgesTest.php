<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\Activity\Facades\Activity;
use Goldnead\AppApi\Events\TokenCreated;
use Goldnead\AppApi\Events\TokenRevoked;
use Goldnead\AppApi\Integrations\Automations\AutomationsBridge;
use Goldnead\AppApi\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\AppApi\Support\EventCatalog;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__.'/../Fakes/automations.php';
require_once __DIR__.'/../Fakes/webhook-manager.php';
require_once __DIR__.'/../Fakes/activity.php';

/**
 * The two token events reach automations, webhook-manager and activity.
 * The siblings are the stand-ins under tests/Fakes, with the shapes of the
 * real ones.
 */
class BridgesTest extends TestCase
{
    protected function setUp(): void
    {
        Automations::$root = null;
        WebhookManager::$triggers = [];
        Activity::$recorded = [];

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The manager's container binding is what the bridge waits for.
        $app->instance('webhook-manager', new \stdClass);
    }

    #[Test]
    public function both_events_are_automation_triggers_and_fire_with_their_payload(): void
    {
        $recorder = Automations::getFacadeRoot();

        // The siblings (teams, offers) register theirs next to these.
        $this->assertSame(EventCatalog::handles(), array_values(array_intersect(array_keys($recorder->triggers), EventCatalog::handles())));
        $this->assertSame('App API', $recorder->triggers['app-api.token.created']['group']);
        $this->assertArrayHasKey('token', $recorder->triggers['app-api.token.created']['output_schema']);

        TokenCreated::dispatch('7', 'sina@example.com', 3, 'Notenpult', ['*'], null);

        $fired = collect($recorder->dispatched)->where('handle', 'app-api.token.created')->values();
        $this->assertCount(1, $fired);
        $this->assertSame(3, $fired[0]['context']['token']['id']);
        $this->assertSame('7', $fired[0]['context']['user']['id']);
    }

    #[Test]
    public function registering_twice_registers_once(): void
    {
        $bridge = app(AutomationsBridge::class);
        $bridge->register();
        $bridge->register();

        TokenRevoked::dispatch('7', null, 3, 'Notenpult', 'user');

        $this->assertCount(1, collect(Automations::getFacadeRoot()->dispatched)->where('handle', 'app-api.token.revoked'));
    }

    #[Test]
    public function both_events_are_webhook_triggers_and_are_handed_to_the_manager(): void
    {
        $this->assertSame(EventCatalog::handles(), array_values(array_intersect(array_keys(WebhookManager::$triggers), EventCatalog::handles())));
        $this->assertTrue(app(WebhookManagerBridge::class)->booted());

        $detected = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $e) use (&$detected) {
            $detected[] = $e->event;
        });

        TokenRevoked::dispatch('7', 'sina@example.com', 3, 'Notenpult', 'cp', '1');

        $this->assertCount(1, $detected);
        $this->assertSame('app-api.token.revoked', $detected[0]->triggerHandle);
        $this->assertSame('7', $detected[0]->sourceReference);
        $this->assertSame('cp', $detected[0]->payload['revoked_by']);
    }

    #[Test]
    public function the_events_are_written_to_the_activity_log(): void
    {
        TokenCreated::dispatch('7', 'sina@example.com', 3, 'Notenpult', ['*'], null);

        $this->assertSame('app-api.token.created', Activity::$recorded[0]['type']);
        $this->assertSame('7', Activity::$recorded[0]['attributes']['subject_id']);
        $this->assertSame('statamic-app-api', Activity::$recorded[0]['attributes']['source']);
    }

    #[Test]
    public function no_payload_carries_a_secret(): void
    {
        $event = new TokenCreated('7', 'sina@example.com', 3, 'Notenpult', ['*'], null);

        $this->assertArrayNotHasKey('plain_text_token', $event->payload()['token']);
        $this->assertArrayNotHasKey('token', array_diff_key($event->payload()['token'], array_flip(['id', 'name', 'abilities', 'expires_at'])));
    }
}
