<?php

/*
 * Stand-in for goldnead/statamic-webhook-manager: the trigger contract, the
 * value object, the `TriggerDetected` event and the facade calls the bridge
 * makes (`registerTrigger`, `triggers()->get`). Same shapes as the real
 * package.
 */

namespace Goldnead\WebhookManager\ValueObjects {
    if (! class_exists(TriggerEvent::class)) {
        class TriggerEvent
        {
            /** @param  array<string, mixed>  $payload */
            public function __construct(
                public readonly string $triggerHandle,
                public readonly string $sourceType,
                public readonly ?string $sourceReference,
                public readonly array $payload,
                public readonly ?string $site = null,
                public readonly ?string $locale = null,
                public readonly bool $isReplay = false,
                public readonly ?\DateTimeImmutable $eventAt = null,
            ) {}
        }
    }
}

namespace Goldnead\WebhookManager\Contracts {
    use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

    if (! interface_exists(TriggerInterface::class)) {
        interface TriggerInterface
        {
            public function handle(): string;

            public function label(): string;

            public function sourceType(): string;

            /** @param  array<string, mixed>  $context */
            public function build(mixed $source, array $context = []): TriggerEvent;
        }
    }
}

namespace Goldnead\WebhookManager\Events {
    use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

    if (! class_exists(TriggerDetected::class)) {
        class TriggerDetected
        {
            public function __construct(public TriggerEvent $event) {}
        }
    }
}

namespace Goldnead\WebhookManager\Facades {
    use Goldnead\WebhookManager\Contracts\TriggerInterface;
    use Illuminate\Support\Collection;

    if (! class_exists(WebhookManager::class)) {
        class WebhookManager
        {
            /** @var array<string, TriggerInterface> */
            public static array $triggers = [];

            public static function registerTrigger(TriggerInterface $trigger): void
            {
                static::$triggers[$trigger->handle()] = $trigger;
            }

            /** @return Collection<string, TriggerInterface> */
            public static function triggers(): Collection
            {
                return collect(static::$triggers);
            }
        }
    }
}
