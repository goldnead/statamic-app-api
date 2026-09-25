<?php

namespace Goldnead\AppApi\Services;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Endpoints;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\OfferHandle;
use Goldnead\StatamicPayments\Support\Catalogue;

/**
 * What the order form shows next to the order button, and which version of
 * it the buyer agreed to.
 *
 * The consent to an immediate start that ends the right of withdrawal
 * (§ 356 Abs. 5 BGB) belongs to digital content only. Whether a product is
 * digital is the catalogue's `digital` (statamic-products, statamic-offers),
 * never a guess here. Digital: an offer's own waiver wording (its
 * `withdrawalTerms()`), else statamic-payments' `order_consent`. Not
 * digital: no consent text, and none is stored with the payment.
 *
 * The version is the offer's terms version, or a hash over the wording: a
 * change of wording is a new version, and a form that showed the old one
 * gets 409 `consent_changed` instead of a purchase under words the buyer
 * never saw.
 */
class CheckoutTerms
{
    /**
     * @return array{product: string|null, offer: string|null, digital: bool, consent_text: string|null, consent_version: string, button_label: string, withdrawal: array<string, mixed>|null}
     */
    public function for(?string $product, ?string $offer): array
    {
        $withdrawal = null;

        if (filled($offer)) {
            $record = class_exists(Offer::class) ? Offer::query()->where('handle', (string) $offer)->first() : null;

            if (! $record instanceof Offer) {
                throw ApiException::make('product_not_found', 404, 'offer');
            }

            $entry = app(Catalogue::class)->find(OfferHandle::of($record)) ?? app(Catalogue::class)->find((string) $record->product);
            $digital = (bool) (($entry ?? [])['digital'] ?? false);
            $text = null;
            $version = null;

            if ($digital) {
                $withdrawal = $record->withdrawalTerms();
                $text = trim((string) ($withdrawal['waiver_text'] ?? '')) ?: $this->defaultText();
                $version = trim((string) ($withdrawal['version'] ?? '')) ?: null;
            }
        } else {
            $entry = filled($product) ? app(Catalogue::class)->find((string) $product) : null;

            if ($entry === null) {
                throw ApiException::make('product_not_found', 404, 'product');
            }

            $digital = (bool) ($entry['digital'] ?? false);
            $text = $digital ? $this->defaultText() : null;
            $version = null;
        }

        return [
            'product' => filled($offer) ? null : (string) $product,
            'offer' => filled($offer) ? (string) $offer : null,
            'digital' => $digital,
            'consent_text' => $text,
            'consent_version' => $version ?? ($text === null ? 'none' : substr(hash('sha256', $text), 0, 12)),
            'button_label' => Endpoints::ORDER_BUTTON,
            'withdrawal' => $withdrawal,
        ];
    }

    protected function defaultText(): string
    {
        return (string) __('statamic-payments::messages.order_consent');
    }
}
