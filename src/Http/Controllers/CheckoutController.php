<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Services\CheckoutStarter;
use Goldnead\AppApi\Services\CheckoutTerms;
use Goldnead\AppApi\Support\Subjects;
use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    /**
     * What the order form must show (§ 312j BGB): the consent text and its
     * version for the checkbox, and the label of the order button.
     */
    public function terms(Request $request, CheckoutTerms $terms): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required_without:offer', 'nullable', 'string', 'max:191'],
            'offer' => ['required_without:product', 'nullable', 'string', 'max:191'],
        ]);

        $found = $terms->for($data['product'] ?? null, $data['offer'] ?? null);
        unset($found['withdrawal']);

        return new JsonResponse($found);
    }

    public function start(Request $request, CheckoutStarter $starter, CheckoutTerms $termsFor): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required_without:offer', 'nullable', 'string', 'max:191'],
            'offer' => ['required_without:product', 'nullable', 'string', 'max:191'],
            'for' => ['nullable', 'in:user,team'],
            'bumps' => ['nullable', 'array', 'max:20'],
            'bumps.*' => ['string', 'max:191'],
            'coupon' => ['nullable', 'string', 'max:64'],
            'pricing_option' => ['nullable', 'string', 'max:64'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'country' => ['nullable', 'string', 'size:2'],
            'confirmed' => ['nullable'],
            'consent_version' => ['required', 'string', 'max:191'],
            'return_url' => ['nullable', 'string', 'max:2048'],
        ]);

        // The order form's checkbox: strictly accepted (true, 1, "yes", "on"),
        // as Laravel's `accepted` reads it. Without it nothing is started. The
        // wording stored with the payment is the server's, never the client's.
        if (Validator::make($request->only('confirmed'), ['confirmed' => ['accepted']])->fails()) {
            throw ApiException::make('consent_required', 422, 'confirmed');
        }

        $terms = $termsFor->for($data['product'] ?? null, $data['offer'] ?? null);

        // The form showed another wording than the one in force now.
        if ($data['consent_version'] !== $terms['consent_version']) {
            throw ApiException::make('consent_changed', 409, 'consent_version', [
                'consent_text' => $terms['consent_text'],
                'consent_version' => $terms['consent_version'],
            ]);
        }

        $user = Users::current($request);
        $team = Subjects::team();

        $started = $starter->start($user, $team, $data, $request->header('Idempotency-Key'), $terms);

        return new JsonResponse($this->present($started['payment'], $started['checkout_url'], $started['reused']), $started['reused'] ? 200 : 201);
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        $user = Users::current($request);
        $record = Payment::query()->find($payment);

        // By the address only when it is confirmed, as for the portal:
        // registering with a stranger's address must not open their orders.
        $email = UserResource::verified($user) === true ? (string) $user->email() : '';

        if (! $record instanceof Payment || ! $this->belongsTo($record, (string) $user->id(), $email)) {
            throw ApiException::notFound();
        }

        return new JsonResponse($this->present($record, null, null));
    }

    /**
     * A signed link into statamic-payments' customer portal, for the user's
     * own address, valid for the portal's link lifetime.
     */
    public function portal(Request $request, LinkTokenizer $links): JsonResponse
    {
        if (! config('statamic-payments.portal.enabled', true)) {
            throw ApiException::make('portal_disabled', 404);
        }

        $user = Users::current($request);

        if (config('app-api.portal.require_verified_email', true) && UserResource::verified($user) !== true) {
            throw ApiException::make('email_unverified', 403);
        }

        return new JsonResponse([
            'url' => $links->issue((string) $user->email(), Brands::stampId()),
            'expires_at' => now()->addMinutes($links->ttlMinutes())->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function present(Payment $payment, ?string $checkoutUrl, ?bool $reused): array
    {
        return array_filter([
            'checkout_url' => $checkoutUrl,
            'reused' => $reused,
            'payment' => [
                'id' => (int) $payment->getKey(),
                'status' => $payment->status,
                'paid' => $payment->isPaid(),
                'product' => $payment->product,
                'amount_cent' => (int) $payment->amount_cent,
                'currency' => $payment->currency,
                'team_id' => isset($payment->meta['team_id']) ? (int) $payment->meta['team_id'] : null,
            ],
        ], fn ($value) => $value !== null);
    }

    protected function belongsTo(Payment $payment, string $userId, string $email): bool
    {
        $meta = (array) ($payment->meta ?? []);

        if (isset($meta['app_api_user_id'])) {
            return (string) $meta['app_api_user_id'] === $userId;
        }

        return $email !== '' && strcasecmp((string) $payment->email, $email) === 0;
    }
}
