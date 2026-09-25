<?php

namespace Goldnead\AppApi\Integrations;

use Goldnead\AppApi\Events\ApiEvent;
use Goldnead\AppApi\Support\EventCatalog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the token events to goldnead/statamic-activity, when installed.
 * Subject is the user who owns the token; the payload goes into
 * `properties` (no secret is in it).
 */
class ActivityRecorder
{
    public const FACADE = 'Goldnead\Activity\Facades\Activity';

    public function subscribe(Dispatcher $events): void
    {
        foreach (EventCatalog::EVENTS as $class) {
            $events->listen($class, [$this, 'record']);
        }
    }

    public function available(): bool
    {
        return (bool) config('app-api.integrations.activity', true) && class_exists(self::FACADE);
    }

    public function record(ApiEvent $event): void
    {
        if (! $this->available()) {
            return;
        }

        $payload = $event->payload();

        try {
            $facade = self::FACADE;
            $facade::record($event::handle(), array_filter([
                'subject_type' => 'user',
                'subject_id' => isset($payload['user']['id']) ? (string) $payload['user']['id'] : null,
                'source' => 'statamic-app-api',
                'properties' => $payload,
            ], fn ($value) => $value !== null));
        } catch (Throwable $e) {
            Log::warning('statamic-app-api: writing the activity failed.', [
                'event' => $event::handle(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
