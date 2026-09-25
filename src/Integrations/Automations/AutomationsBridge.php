<?php

namespace Goldnead\AppApi\Integrations\Automations;

use Goldnead\AppApi\Events\ApiEvent;
use Goldnead\AppApi\Support\EventCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers the token events as automation triggers, through the sibling's
 * generic `Automations::registerEventTrigger()`. The context is the event's
 * `payload()`, the same fields a webhook gets.
 */
class AutomationsBridge
{
    public const FACADE = 'Goldnead\StatamicAutomations\Facades\Automations';

    protected bool $registered = false;

    public function available(): bool
    {
        return (bool) config('app-api.integrations.automations', true) && class_exists(self::FACADE);
    }

    /** Idempotent: Statamic fires booted callbacks more than once. */
    public function register(): void
    {
        if ($this->registered || ! $this->available()) {
            return;
        }

        try {
            $facade = self::FACADE;
            $root = $facade::getFacadeRoot();

            if (! is_object($root) || ! method_exists($root, 'registerEventTrigger')) {
                return;
            }

            foreach (EventCatalog::all() as $event) {
                $root->registerEventTrigger($event['class'], [
                    'handle' => $event['handle'],
                    'label' => $event['label'],
                    'description' => $event['description'],
                    'group' => 'App API',
                    'payload' => fn (ApiEvent $fired) => $fired->payload(),
                    'output_schema' => self::outputSchema($event['handle']),
                ]);
            }

            $this->registered = true;
        } catch (Throwable $e) {
            Log::warning('statamic-app-api: the automation triggers could not be registered.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    /** @return array<string, mixed> */
    public static function outputSchema(string $handle): array
    {
        $user = ['id' => 'string', 'email' => 'string'];

        return match ($handle) {
            'app-api.token.created' => ['user' => $user, 'token' => ['id' => 'integer', 'name' => 'string', 'abilities' => 'array', 'expires_at' => 'datetime']],
            'app-api.token.revoked' => ['user' => $user, 'token' => ['id' => 'integer', 'name' => 'string'], 'revoked_by' => 'string', 'actor_id' => 'string'],
            default => [],
        };
    }
}
