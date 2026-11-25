<?php

namespace Goldnead\AppApi\Integrations\WebhookManager;

use Goldnead\AppApi\Events\ApiEvent;
use Goldnead\AppApi\Support\EventCatalog;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers the token events as webhook triggers: one trigger per event, and
 * the event re-emitted as the manager's `TriggerDetected`. Nothing touches
 * the manager's classes before the `class_exists` guard.
 */
class WebhookManagerBridge
{
    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('app-api.integrations.webhook_manager', true)
            && class_exists(WebhookManager::class);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The binding appears only once the manager's provider booted. Installed
        // but unbound after the app booted is not a normal state: say so.
        if (! app()->bound('webhook-manager')) {
            Log::warning('statamic-app-api: webhook-manager is installed but its binding is missing; the API events are not sent to it.');

            return;
        }

        $this->booted = true;

        foreach (EventCatalog::all() as $event) {
            try {
                WebhookManager::registerTrigger(new ApiTrigger($event['handle'], $event['label']));
            } catch (Throwable $e) {
                Log::warning('statamic-app-api: webhook trigger ['.$event['handle'].'] could not be registered: '.$e->getMessage());

                continue;
            }

            $handle = $event['handle'];

            $events->listen($event['class'], function (ApiEvent $fired) use ($handle): void {
                $this->dispatch($handle, $fired);
            });
        }
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    protected function dispatch(string $handle, ApiEvent $event): void
    {
        try {
            $trigger = WebhookManager::triggers()->get($handle);

            if (! $trigger) {
                return;
            }

            event(new TriggerDetected($trigger->build($event)));
        } catch (Throwable $e) {
            // The payload only decorates the log line: reading it must not throw
            // out of the listener and replace the original failure.
            $payload = rescue(fn () => $event->payload(), [], false);

            Log::warning('statamic-app-api: webhook dispatch failed for ['.$handle.']: '.$e->getMessage(), [
                'event' => $handle,
                'user_id' => $payload['user']['id'] ?? null,
                'token_id' => $payload['token']['id'] ?? null,
            ]);
        }
    }
}
