<?php

namespace Goldnead\AppApi\Services;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Statamic\Auth\User;
use Throwable;

/**
 * Starts a purchase the way the siblings' own pages do, and hands back the
 * provider's URL instead of redirecting to it.
 *
 * Nothing is decided here that a sibling decides already:
 * - the price, what a handle is and whether it can be sold: payments'
 *   catalogue (`Checkout::start()` refuses an unknown handle);
 * - one payment or the start of a subscription: `Subscriptions::planFor()`,
 *   as the funnel does;
 * - an offer's bumps, coupon, pricing option, amount, country rule and its
 *   quantity limit: statamic-offers' `Basket` and `Offer::isSellable()`;
 * - a team as buyer, with its billing address and `details['for']`:
 *   statamic-teams' `checkoutBuyer()`/`checkoutDetails()`, and the payer
 *   needs `manage billing` in that team.
 *
 * What this adds is the part only an API has: the same request twice (a
 * double click, a retry after a timeout) answers with the first checkout
 * instead of starting a second payment. Under a lock, so two requests at
 * the same moment cannot both start one.
 */
class CheckoutStarter
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{payment: Payment, checkout_url: string, reused: bool}
     */
    public function start(User $user, ?Team $team, array $input, ?string $idempotencyKey = null): array
    {
        $key = $this->key($user, $team, $input, $idempotencyKey);
        $ttl = max(1, (int) config('app-api.checkout.idempotency_seconds', 300));

        return Cache::lock($key.':lock', 30)->block(15, function () use ($key, $ttl, $user, $team, $input) {
            if ($previous = $this->previous($key)) {
                return $previous + ['reused' => true];
            }

            $started = $this->begin($user, $team, $input);

            Cache::put($key, ['payment' => (int) $started['payment']->getKey(), 'checkout_url' => $started['checkout_url']], $ttl);

            return $started + ['reused' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{payment: Payment, checkout_url: string}
     */
    protected function begin(User $user, ?Team $team, array $input): array
    {
        if (($input['for'] ?? 'user') === 'team') {
            if ($team === null) {
                throw ApiException::make('team_required', 422, 'for');
            }

            // The same rule as `Teams::checkout()`: paying for a team is
            // spending its money.
            if (! Teams::can($user, $team, 'manage billing')) {
                throw ApiException::make('forbidden', 403, 'for');
            }
        } else {
            $team = null;
        }

        $basket = null;
        $details = ['meta' => ['app_api_user_id' => (string) $user->id()]];

        if (filled($input['offer'] ?? null)) {
            [$handles, $basket, $details] = $this->fromOffer((string) $input['offer'], $input, $details);
        } else {
            $handle = (string) ($input['product'] ?? '');

            if ($handle === '' || app(Catalogue::class)->find($handle) === null) {
                throw ApiException::make('product_not_found', 404, 'product');
            }

            $handles = [$handle];
            $details['consent_at'] = now();
            $details['consent_text'] = (string) __('statamic-payments::messages.order_consent');
        }

        $buyer = array_filter([
            'email' => $user->email(),
            'name' => $user->name(),
            'country' => isset($input['country']) ? strtoupper((string) $input['country']) : null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($team !== null) {
            $buyer = Teams::checkoutBuyer($team, $user) + $buyer;
            $teamDetails = Teams::checkoutDetails($team, $user);
            $details['for'] = $teamDetails['for'];
            $details['meta'] = array_merge($teamDetails['meta'], $details['meta']);

            foreach (['country', 'country_source'] as $column) {
                if (isset($teamDetails[$column])) {
                    $details[$column] = $teamDetails[$column];
                }
            }
        }

        $returnUrl = $this->returnUrl($input['return_url'] ?? null);
        $subscriptions = app(Subscriptions::class);
        $plan = $subscriptions->planFor($handles[0]);

        if ($plan !== null && ! $subscriptions->canStart()) {
            $basket?->releaseCoupon();

            throw ApiException::make('checkout_refused', 409);
        }

        $starter = $plan !== null ? $subscriptions : app(Checkout::class);

        try {
            $result = $plan !== null
                ? $subscriptions->start($handles, $buyer, $returnUrl, $details, $basket?->discount())
                : app(Checkout::class)->start($handles, $buyer, $returnUrl, $basket?->discount(), $details);
        } catch (InvalidArgumentException $e) {
            $basket?->releaseCoupon();

            throw $e;
        } catch (Throwable $e) {
            $basket?->releaseCoupon();

            Log::error('statamic-app-api: the payment could not be created at the provider.', [
                'products' => $handles,
                'error' => $e->getMessage(),
            ]);

            throw ApiException::make('provider_unavailable', 503);
        }

        if ($result === null) {
            $basket?->releaseCoupon();
            $refusal = method_exists($starter, 'refusal') ? $starter->refusal() : null;

            throw ApiException::make('checkout_refused', 422, null, [], is_string($refusal) && $refusal !== '' ? $refusal : null);
        }

        return ['payment' => $result->payment, 'checkout_url' => $result->checkoutUrl];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $details
     * @return array{0: list<string>, 1: Basket, 2: array<string, mixed>}
     */
    protected function fromOffer(string $handle, array $input, array $details): array
    {
        if (! class_exists(Offer::class)) {
            throw ApiException::make('product_not_found', 404, 'offer');
        }

        $offer = Offer::query()->where('handle', $handle)->first();

        if (! $offer instanceof Offer) {
            throw ApiException::make('product_not_found', 404, 'offer');
        }

        if (! $offer->isSellable()) {
            throw $offer->remainingQuantity() === 0
                ? ApiException::make('sold_out', 409, 'offer')
                : ApiException::make('offer_unavailable', 409, 'offer');
        }

        try {
            $basket = Basket::make(
                $offer,
                array_values(array_filter((array) ($input['bumps'] ?? []), 'is_string')),
                isset($input['coupon']) ? (string) $input['coupon'] : null,
                isset($input['pricing_option']) ? (string) $input['pricing_option'] : null,
                isset($input['amount']) ? (int) $input['amount'] : null,
                isset($input['country']) ? strtoupper((string) $input['country']) : null,
            );
        } catch (InvalidArgumentException $e) {
            $message = method_exists($e, 'buyerMessage') ? $e->buyerMessage() : $e->getMessage();
            $field = isset($input['pricing_option']) && ! method_exists($e, 'buyerMessage') ? 'pricing_option' : 'amount';

            throw ApiException::make('validation_failed', 422, $field, [], $message);
        } catch (Throwable $e) {
            if (method_exists($e, 'buyerMessage')) {
                throw ApiException::make('offer_unavailable', 409, 'country', [], $e->buyerMessage());
            }

            throw $e;
        }

        // The terms the buyer agreed to, frozen onto the payment as the funnel
        // does: the waiver's wording with its version, and the whole terms.
        $terms = $offer->withdrawalTerms();
        $version = trim((string) ($terms['version'] ?? ''));
        $text = (string) ($terms['waiver_text'] ?? '');

        $details['consent_at'] = now();
        $details['consent_text'] = ($text !== '' ? $text : (string) __('statamic-payments::messages.order_consent')).($version !== '' ? ' ['.$version.']' : '');
        $details['meta'] = array_merge($details['meta'], $basket->paymentMeta(), array_filter([
            'withdrawal' => $terms,
            'access' => $offer->accessWindow(),
        ]));

        return [$basket->handles(), $basket, $details];
    }

    /** @return array{payment: Payment, checkout_url: string}|null */
    protected function previous(string $key): ?array
    {
        $cached = Cache::get($key);

        if (! is_array($cached)) {
            return null;
        }

        $payment = Payment::query()->find($cached['payment'] ?? null);

        // Only a checkout that can still be paid is handed out again. A paid,
        // failed or expired one means the next click is a new purchase.
        if (! $payment instanceof Payment || ! in_array($payment->status, [Payment::STATUS_INITIATED, Payment::STATUS_OPEN], true)) {
            Cache::forget($key);

            return null;
        }

        return ['payment' => $payment, 'checkout_url' => (string) $cached['checkout_url']];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function key(User $user, ?Team $team, array $input, ?string $idempotencyKey): string
    {
        $scope = (string) $user->id().'|'.($team?->getKey() ?? '-');

        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            return 'app-api:checkout:'.hash('sha256', $scope.'|key|'.trim($idempotencyKey));
        }

        $relevant = array_intersect_key($input, array_flip(['product', 'offer', 'for', 'bumps', 'coupon', 'pricing_option', 'amount', 'country']));
        ksort($relevant);

        return 'app-api:checkout:'.hash('sha256', $scope.'|'.json_encode($relevant));
    }

    protected function returnUrl(mixed $path): ?string
    {
        foreach ([$path, config('app-api.checkout.return_url')] as $candidate) {
            // A path on this site only: an absolute URL in a request body is
            // an open redirect with the provider's help.
            if (is_string($candidate) && preg_match('#^/(?![/\\\\])#', $candidate) === 1) {
                return url($candidate);
            }
        }

        return null;
    }
}
