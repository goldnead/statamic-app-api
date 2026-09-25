<?php

namespace Goldnead\AppApi\Tests\Fakes;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use RuntimeException;

/**
 * Stands in for the payment provider: counts what it was asked to create and
 * answers with a checkout URL of its own. `fail` makes the next call throw,
 * as a provider that cannot be reached does.
 */
class FakeGateway implements PaymentGateway
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    public bool $fail = false;

    public function createPayment(array $payload): CheckoutSession
    {
        if ($this->fail) {
            throw new RuntimeException('provider down');
        }

        $this->created[] = $payload;
        $n = count($this->created);

        return new CheckoutSession('tr_test_'.$n, 'https://pay.test/checkout/'.$n);
    }

    public function fetch(string $providerId): RemotePayment
    {
        return new RemotePayment($providerId, 'open');
    }

    public function provider(): string
    {
        return 'mollie';
    }
}
