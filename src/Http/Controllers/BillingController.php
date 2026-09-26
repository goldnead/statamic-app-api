<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Services\Billing;
use Goldnead\AppApi\Support\Users;
use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\CancellationOutcome;
use Goldnead\StatamicPayments\Support\Cancellations;
use Goldnead\StatamicPayments\Support\LocalTime;
use Goldnead\StatamicPayments\Support\Money;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Statamic\Auth\User;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The customer area of statamic-payments, as JSON: orders, invoices and
 * credit notes, agreements, and what the customer portal lets a buyer do
 * with them (cancel, pause, resume, switch, change the payment method).
 *
 * Translation only. Who may see a row is {@see Billing}; every change goes
 * through the same classes of statamic-payments the portal calls
 * (`Cancellations::cancel()`, `SubscriptionPauses`, `SubscriptionSwitches`,
 * `MandateGateway`), so the provider is asked first and the same events
 * fire. No elevated session anywhere here: § 312k BGB wants the
 * cancellation without extra hurdles, and the portal itself asks for no
 * more than a mailed link.
 */
class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $billing = $this->billing($request);
        $payments = $billing->payments();
        $documents = $billing->documentsOf($payments->map(fn ($payment) => (int) $payment->getKey())->all());

        return new JsonResponse([
            'payments' => $payments->map(fn ($payment) => $billing->presentPayment($payment, $documents))->values()->all(),
            'subscriptions' => $billing->subscriptions()->map(fn ($subscription) => $billing->presentSubscription($subscription))->values()->all(),
            'team' => $billing->teamSummary(),
            'documents_available' => Billing::documentsAvailable(),
            'links' => [
                'cancellation_url' => Billing::cancellationUrl(),
                'withdrawal_url' => Billing::withdrawalUrl(),
            ],
            'display' => [
                'timezone' => LocalTime::zone(),
                'anrede' => Anrede::current(),
            ],
        ]);
    }

    public function payment(Request $request, string $payment): JsonResponse
    {
        $billing = $this->billing($request);

        return new JsonResponse(['payment' => $billing->presentPayment($billing->payment($payment), lines: true)]);
    }

    public function documents(Request $request): JsonResponse
    {
        $billing = $this->billing($request);

        return new JsonResponse([
            'data' => $billing->documents()->map(fn ($document) => $billing->presentDocument($document))->values()->all(),
            'documents_available' => Billing::documentsAvailable(),
        ]);
    }

    /**
     * The PDF, streamed to the person it belongs to. Rendered through
     * statamic-invoices' own `PdfRenderer`, the binding its CP download and
     * mail use. `attachment` and `no-store`, as the portal serves it.
     */
    public function document(Request $request, string $document): Response
    {
        $record = $this->billing($request)->document($document);
        $renderer = app(Billing::PDF_RENDERER);
        $filename = (preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $record->number) ?: 'rechnung').'.pdf';

        return response($renderer->render($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $billing = $this->billing($request);

        return new JsonResponse([
            'data' => $billing->subscriptions()->map(fn ($subscription) => $billing->presentSubscription($subscription))->values()->all(),
        ]);
    }

    public function subscription(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);

        return new JsonResponse(['subscription' => $billing->presentSubscription($billing->subscription($subscription))]);
    }

    // Cancel (§ 312k BGB) ---------------------------------------------------------

    /**
     * What the portal's confirmation page shows before „Jetzt kündigen":
     * the contract, the price, when it began, until when it is paid, and
     * what the cancellation does.
     */
    public function cancelPreview(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $this->cancelHereOr409($billing, $record);

        $until = Billing::paidUntil($record);
        $running = $record->isRunning() || $record->isClaimed();

        return new JsonResponse([
            'subscription' => $billing->presentSubscription($record),
            'can_cancel' => $running,
            'confirmation' => [
                'title' => Anrede::trans('statamic-payments::portal.cancel_title'),
                'intro' => Anrede::trans('statamic-payments::portal.cancel_intro'),
                'contract_label' => Anrede::trans('statamic-payments::portal.cancel_contract'),
                'contract' => $billing->nameOf($record->product),
                'price_label' => Anrede::trans('statamic-payments::portal.cancel_price'),
                'price' => Display::money((int) $record->amount_cent, $record->currency).' · '.Display::rhythm((string) $record->interval),
                'started_label' => Anrede::trans('statamic-payments::portal.cancel_started'),
                'started_at' => Billing::iso($record->created_at),
                'started_at_display' => LocalTime::portalDate($record->created_at),
                'paid_until_label' => Anrede::trans('statamic-payments::portal.cancel_paid_until'),
                'paid_until' => Billing::iso($until),
                'paid_until_display' => LocalTime::portalDate($until),
                'effect' => ! $running
                    ? Anrede::trans('statamic-payments::portal.cancel_not_live')
                    : ($until
                        ? Anrede::trans('statamic-payments::portal.cancel_effect_until', ['date' => LocalTime::portalDate($until)])
                        : Anrede::trans('statamic-payments::portal.cancel_effect')),
                'button_label' => Anrede::trans('statamic-payments::portal.cancel_now'),
                'abort_label' => Anrede::trans('statamic-payments::portal.cancel_abort'),
            ],
        ]);
    }

    /**
     * „Jetzt kündigen": payments' own sequence (`Support\Cancellations`, the
     * one the portal runs): the provider first, which writes nothing unless
     * it confirmed and fires `SubscriptionCancelled`; then the confirmation
     * in Textform to the person who cancelled, logged at the agreement's
     * latest payment. A team's agreement: a copy to the team's billing
     * address when there is one and it is another
     * (`billing.cancellation_copy_to_team`).
     */
    public function cancel(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $billing->authorizeChange($record);
        $this->cancelHereOr409($billing, $record);
        $this->confirmedOr422($request);

        $email = (string) Users::current($request)->email();
        $copies = config('app-api.billing.cancellation_copy_to_team', true)
            ? array_filter([$billing->teamBillingEmailOf($record)])
            : [];

        // Under the agreement's brand: sender, wording and form of address of
        // the confirmation are that brand's, whatever this request resolved.
        $outcome = Brands::runFor(
            (int) $record->brand_id,
            fn () => app(Cancellations::class)->cancel($record, $email, array_values($copies)),
        );

        return match ($outcome->status) {
            CancellationOutcome::BUSY => throw ApiException::make('cancel_busy', 409, null, [], Anrede::trans('statamic-payments::subscriptions.portal_cancel_busy')),
            CancellationOutcome::FAILED => throw ApiException::make('cancel_failed', 503, null, [], Anrede::trans('statamic-payments::portal.cancel_failed')),
            // Already over: the confirmation the buyer was going to see, no
            // second mail.
            CancellationOutcome::ALREADY_ENDED => $this->cancelled($billing, $outcome->subscription, $email, $outcome->moment ?? Carbon::now(), $outcome->until, true, null),
            default => $this->cancelled($billing, $outcome->subscription, $email, $outcome->moment ?? Carbon::now(), $outcome->until, false, $outcome->confirmationSent, $outcome->copiedTo),
        };
    }

    /** @param  list<string>  $copiedTo */
    protected function cancelled(Billing $billing, Subscription $record, string $email, Carbon $moment, ?Carbon $until, bool $already, ?bool $sent, array $copiedTo = []): JsonResponse
    {
        return new JsonResponse([
            'cancelled' => true,
            'already' => $already,
            'moment' => Billing::iso($moment),
            'moment_display' => LocalTime::portalDate($moment).' '.LocalTime::portalTime($moment),
            'paid_until' => Billing::iso($until),
            'paid_until_display' => LocalTime::portalDate($until),
            'title' => Anrede::trans('statamic-payments::portal.cancelled_title'),
            'message' => Anrede::trans('statamic-payments::portal.cancelled_confirmation', [
                'name' => $billing->nameOf($record->product),
                'date' => LocalTime::portalDate($moment),
                'time' => LocalTime::portalTime($moment),
            ]),
            'until_message' => $until ? Anrede::trans('statamic-payments::portal.cancelled_until', ['date' => LocalTime::portalDate($until)]) : null,
            'mail_sent' => $sent,
            'mail_message' => match ($sent) {
                true => Anrede::trans('statamic-payments::portal.cancelled_mailed', ['email' => $email]),
                false => Anrede::trans('statamic-payments::portal.cancelled_not_mailed', ['email' => $email]),
                null => null,
            },
            // Where a copy of the confirmation went as well (a team's billing
            // address).
            'copied_to' => $copiedTo,
            'subscription' => $billing->presentSubscription($record),
        ]);
    }

    protected function cancelHereOr409(Billing $billing, Subscription $record): void
    {
        if (! $billing->mayCancelHere($record)) {
            throw ApiException::make('cancel_elsewhere', 409, null, [
                'cancellation_url' => Billing::cancellationUrl(),
            ], Anrede::trans('statamic-payments::subscriptions.portal_cancel_elsewhere'));
        }
    }

    protected function confirmedOr422(Request $request): void
    {
        if (Validator::make($request->only('confirmed'), ['confirmed' => ['accepted']])->fails()) {
            throw ApiException::make('confirmation_required', 422, 'confirmed');
        }
    }

    // Pause and resume --------------------------------------------------------------

    public function pausePreview(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);

        if (! app(SubscriptionPauses::class)->portalMayPause($record)) {
            throw $this->pauseUnavailable();
        }

        $name = $billing->nameOf($record->product);

        return new JsonResponse([
            'subscription' => $billing->presentSubscription($record),
            'min_date' => Carbon::tomorrow()->toDateString(),
            'texts' => [
                'title' => Anrede::trans('statamic-payments::subscriptions.portal_pause_title'),
                'intro' => Anrede::trans('statamic-payments::subscriptions.portal_pause_intro', ['name' => $name]),
                'effect' => Anrede::trans('statamic-payments::subscriptions.portal_pause_effect'),
                'date_help' => Anrede::trans('statamic-payments::subscriptions.portal_pause_date_help'),
                'button_label' => Anrede::trans('statamic-payments::subscriptions.portal_pause_now'),
            ],
        ]);
    }

    public function pause(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $billing->authorizeChange($record);
        $pauses = app(SubscriptionPauses::class);

        if (! $pauses->portalMayPause($record)) {
            throw $this->pauseUnavailable();
        }

        $resumeOn = null;
        $raw = trim((string) $request->input('resume_on', ''));

        if ($raw !== '') {
            try {
                $resumeOn = Carbon::parse($raw)->startOfDay();
            } catch (Throwable) {
                $resumeOn = null;
            }

            if ($resumeOn === null || $resumeOn->lte(Carbon::today())) {
                throw ApiException::make('pause_date_invalid', 422, 'resume_on', [], Anrede::trans('statamic-payments::subscriptions.portal_pause_date_invalid'));
            }
        }

        if (! $pauses->pause($record, $resumeOn, 'portal')) {
            throw ApiException::make('pause_failed', 503, null, [], Anrede::trans('statamic-payments::subscriptions.portal_pause_failed'));
        }

        return $this->changed($billing, $record, Anrede::trans('statamic-payments::subscriptions.portal_paused'));
    }

    public function resume(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $billing->authorizeChange($record);
        $pauses = app(SubscriptionPauses::class);

        if (! $record->isPaused() || ! $pauses->portalAllows($record)) {
            throw $this->pauseUnavailable();
        }

        if (! $pauses->resume($record, 'portal')) {
            throw ApiException::make('resume_failed', 503, null, [], Anrede::trans('statamic-payments::subscriptions.portal_resume_failed'));
        }

        $fresh = $record->fresh() ?? $record;

        return $this->changed($billing, $fresh, Anrede::trans('statamic-payments::subscriptions.portal_resumed', [
            'date' => LocalTime::portalDate($fresh->next_payment_at),
        ]));
    }

    protected function pauseUnavailable(): ApiException
    {
        return ApiException::make('pause_unavailable', 409, null, [], Anrede::trans('statamic-payments::subscriptions.portal_pause_unavailable'));
    }

    // Switch ------------------------------------------------------------------------

    public function switchPreview(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $switches = app(SubscriptionSwitches::class);
        $targets = $switches->targetsFor($record, portal: true);

        if ($targets === []) {
            throw $this->switchUnavailable();
        }

        $choices = [];

        foreach ($targets as $handle => $name) {
            $preview = $switches->preview($record, $handle);

            if ($preview === null) {
                continue;
            }

            $choices[] = [
                'handle' => $handle,
                'name' => $name,
                'amount_cent' => (int) $preview['to_amount_cent'],
                'amount' => Display::money((int) $preview['to_amount_cent'], $record->currency),
                'rhythm' => Display::rhythm((string) $record->interval),
                'immediate' => (bool) $preview['immediate'],
                'effect' => $preview['immediate']
                    ? ($preview['proration_cent'] > 0
                        ? Anrede::trans('statamic-payments::subscriptions.portal_switch_now_charge', ['amount' => Display::money((int) $preview['proration_cent'], $record->currency)])
                        : Anrede::trans('statamic-payments::subscriptions.portal_switch_now_free'))
                    : Anrede::trans('statamic-payments::subscriptions.portal_switch_later', ['date' => LocalTime::portalDate($preview['effective_at'] ?? null)]),
            ];
        }

        return new JsonResponse([
            'subscription' => $billing->presentSubscription($record),
            'choices' => $choices,
            'texts' => [
                'title' => Anrede::trans('statamic-payments::subscriptions.portal_switch_title'),
                'intro' => Anrede::trans('statamic-payments::subscriptions.portal_switch_intro', [
                    'name' => $billing->nameOf($record->product),
                    'amount' => Display::money($record->chargedCent(), $record->currency),
                ]),
                'button_label' => Anrede::trans('statamic-payments::subscriptions.portal_switch_now'),
            ],
        ]);
    }

    public function switch(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $billing->authorizeChange($record);
        $switches = app(SubscriptionSwitches::class);
        $to = (string) $request->input('to', '');

        if (! array_key_exists($to, $switches->targetsFor($record, portal: true))) {
            throw $this->switchUnavailable();
        }

        if (! $switches->switch($record, $to, 'portal')) {
            throw ApiException::make('switch_failed', 503, null, [], Anrede::trans('statamic-payments::subscriptions.portal_switch_failed'));
        }

        return $this->changed($billing, $record->fresh() ?? $record, Anrede::trans('statamic-payments::subscriptions.portal_switched', ['name' => $billing->nameOf($to)]));
    }

    protected function switchUnavailable(): ApiException
    {
        return ApiException::make('switch_unavailable', 409, null, [], Anrede::trans('statamic-payments::subscriptions.portal_switch_unavailable'));
    }

    // Payment method ----------------------------------------------------------------

    /**
     * Where to send the buyer to put a new payment method on file: the
     * provider's page (Mollie: a small verification charge that creates the
     * mandate, the amount is in `verification`). The portal's sequence
     * (`PaymentMethodController::start()`), with the app's page as return.
     */
    public function paymentMethod(Request $request, string $subscription): JsonResponse
    {
        $billing = $this->billing($request);
        $record = $billing->subscription($subscription);
        $billing->authorizeChange($record);
        $gateway = $billing->gatewayOf($record);

        if (! $gateway instanceof MandateGateway || ! $gateway->supportsMandateUpdate() || ! $record->isRunning() || $record->customer_reference === '') {
            throw ApiException::make('method_unavailable', 409, null, [], Anrede::trans('statamic-payments::portal.method_unavailable'));
        }

        $verification = $gateway->mandateVerificationCent();

        try {
            $session = $gateway->startMandateUpdate($record->customer_reference, [
                'amount' => [
                    'currency' => $record->currency,
                    'value' => Money::format($verification, $record->currency),
                ],
                'description' => Anrede::trans('statamic-payments::portal.method_charge_description'),
                'redirectUrl' => $this->returnUrl($request->input('return_url')),
                'metadata' => [
                    'statamic_payments' => 'mandate_update',
                    'subscription_id' => $record->getKey(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('app-api: the provider would not start a payment-method change.', [
                'subscription_id' => $record->getKey(),
                'exception' => $e->getMessage(),
            ]);

            throw ApiException::make('method_failed', 503, null, [], Anrede::trans('statamic-payments::portal.method_failed'));
        }

        Log::info('app-api: a buyer was sent to the provider to put a new payment method on file.', [
            'subscription_id' => $record->getKey(),
        ]);

        return new JsonResponse([
            'url' => $session->checkoutUrl,
            'verification' => Display::money($verification, $record->currency),
            'note' => Anrede::trans('statamic-payments::portal.method_note', ['amount' => Display::money($verification, $record->currency)]),
            'returned_message' => Anrede::trans('statamic-payments::portal.method_returned'),
        ]);
    }

    /** A path on this site only; else the configured page, else the start page. */
    protected function returnUrl(mixed $path): string
    {
        foreach ([$path, config('app-api.billing.return_url')] as $candidate) {
            if (is_string($candidate) && preg_match('#^/(?![/\\\\])#', $candidate) === 1) {
                return url($candidate);
            }
        }

        return url('/');
    }

    // ---------------------------------------------------------------------------------

    protected function changed(Billing $billing, Subscription $record, string $message): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'subscription' => $billing->presentSubscription($record->fresh() ?? $record),
        ]);
    }

    protected function billing(Request $request): Billing
    {
        /** @var User $user */
        $user = Users::current($request);

        return Billing::for($user);
    }
}
