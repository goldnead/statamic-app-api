<?php

namespace Goldnead\AppApi\Integrations\WebhookManager;

use Goldnead\AppApi\Events\ApiEvent;
use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * One event of this addon as a webhook-manager trigger. Loaded only when the
 * manager is installed (see {@see WebhookManagerBridge}).
 */
class ApiTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $label,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sourceType(): string
    {
        return 'user';
    }

    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $payload = $source instanceof ApiEvent ? $source->payload() : (array) $source;
        $userId = $payload['user']['id'] ?? null;

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: $userId === null ? null : (string) $userId,
            payload: $payload + ['event' => $this->handle],
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable,
        );
    }
}
