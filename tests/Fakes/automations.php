<?php

/*
 * Stand-in for goldnead/statamic-automations: `Automations::registerEventTrigger()`
 * with the behaviour the real one has (one listener per event class, which
 * hands the event to the dispatcher). Here the "dispatcher" records what it
 * got, so a test sees both halves: registered, and fired with context.
 */

namespace Goldnead\StatamicAutomations\Facades {
    use Illuminate\Support\Facades\Event;

    if (! class_exists(Automations::class)) {
        class AutomationsRecorder
        {
            /** @var array<string, array<string, mixed>> */
            public array $triggers = [];

            /** @var list<array{handle: string, context: array<string, mixed>}> */
            public array $dispatched = [];

            /** @param  array<string, mixed>  $definition */
            public function registerEventTrigger(string $eventClass, array $definition): self
            {
                $handle = $definition['handle'];
                $this->triggers[$handle] = $definition + ['event' => $eventClass];

                Event::listen($eventClass, function ($event) use ($handle, $definition) {
                    $payload = $definition['payload'] ?? null;
                    $this->dispatched[] = [
                        'handle' => $handle,
                        'context' => is_callable($payload) ? $payload($event) : [],
                    ];
                });

                return $this;
            }
        }

        class Automations
        {
            public static ?AutomationsRecorder $root = null;

            public static function getFacadeRoot(): AutomationsRecorder
            {
                return static::$root ??= new AutomationsRecorder;
            }
        }
    }
}
