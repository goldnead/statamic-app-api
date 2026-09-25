<?php

namespace Goldnead\AppApi\Tests\Fakes;

use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use RuntimeException;

/**
 * The provider for running agreements: cancels (or refuses to, with
 * `refuseCancel`), and starts a mandate change with a URL of its own.
 * Counts what it was asked.
 */
class FakeSubscriptionGateway extends FakeGateway implements MandateGateway, SubscriptionGateway
{
    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array<string, mixed>> */
    public array $mandates = [];

    public bool $refuseCancel = false;

    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function rememberBuyer(array $buyer): string
    {
        return 'cst_test';
    }

    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        return new RemotePayment('tr_again', 'paid');
    }

    public function supportsSubscriptions(): bool
    {
        return true;
    }

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        return new RemoteSubscription('sub_new', 'active');
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        if ($this->refuseCancel) {
            throw new RuntimeException('provider refuses');
        }

        $this->cancelled[] = $subscriptionId;

        return new RemoteSubscription($subscriptionId, 'canceled');
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        return new RemoteSubscription($subscriptionId, 'active');
    }

    public function supportsMandateUpdate(): bool
    {
        return true;
    }

    public function startMandateUpdate(string $customerReference, array $payload): CheckoutSession
    {
        $this->mandates[] = ['customer' => $customerReference] + $payload;

        return new CheckoutSession('tr_mandate', 'https://pay.test/mandate/'.count($this->mandates));
    }

    public function mandateVerificationCent(): int
    {
        return 1;
    }
}
