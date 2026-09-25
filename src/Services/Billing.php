<?php

namespace Goldnead\AppApi\Services;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Subjects;
use Goldnead\AppApi\Support\UserResource;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Gateways;
use Goldnead\StatamicPayments\Support\LocalTime;
use Goldnead\StatamicPayments\Support\SubscriptionPauses;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statamic\Auth\User;
use Throwable;

/**
 * Whose orders and agreements a request may see, and how they read.
 *
 * The same two conditions as the customer portal of statamic-payments, with
 * the signed-in user in place of the mailed link:
 *
 * - **the user's own rows**: the ones the app recorded for this user id
 *   (`meta.app_api_user_id`), and rows without that note whose address is
 *   the user's **confirmed** address (the portal's rule; an unconfirmed
 *   address opens nothing, or registering with a stranger's address would
 *   open their orders);
 * - **the current team's rows** (`meta.team_id`), when the user holds
 *   `view billing` in that team.
 *
 * Both narrowed to the brand, like the portal (`Brands::only()`). A row
 * outside these is a 404, never a 403: a numbered URL must not tell whether
 * a stranger's order exists. Changing a team's agreement needs
 * `manage billing` there, whoever paid.
 *
 * Every text comes from statamic-payments (its `anrede` setting, its display
 * time zone), so the app says what the portal says.
 */
class Billing
{
    public const INVOICE_MODEL = 'Goldnead\Invoices\Models\Invoice';

    public const PDF_RENDERER = 'Goldnead\Invoices\Contracts\PdfRenderer';

    public function __construct(protected User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    // Who ---------------------------------------------------------------------

    public function team(): ?object
    {
        return Subjects::team();
    }

    public function canViewTeam(): bool
    {
        return $this->teamCan('view billing') || $this->teamCan('manage billing');
    }

    public function canManageTeam(): bool
    {
        return $this->teamCan('manage billing');
    }

    protected function teamCan(string $permission): bool
    {
        $team = $this->team();

        if ($team === null || ! class_exists(Teams::class)) {
            return false;
        }

        try {
            return Teams::can($this->user, $team, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{id: int, name: string, can_view_billing: bool, can_manage_billing: bool}|null */
    public function teamSummary(): ?array
    {
        $team = $this->team();

        if ($team === null) {
            return null;
        }

        return [
            'id' => (int) $team->getKey(),
            'name' => (string) ($team->name ?? ''),
            'can_view_billing' => $this->canViewTeam(),
            'can_manage_billing' => $this->canManageTeam(),
        ];
    }

    // What --------------------------------------------------------------------

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return $this->paymentsQuery()
            ->with('items')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($this->limit())
            ->get();
    }

    public function payment(string|int $id): Payment
    {
        $payment = $this->paymentsQuery()->with('items')->whereKey((int) $id)->first();

        if (! $payment instanceof Payment) {
            throw ApiException::notFound();
        }

        return $payment;
    }

    /** @return Collection<int, Subscription> */
    public function subscriptions(): Collection
    {
        return $this->subscriptionsQuery()->orderByDesc('id')->limit($this->limit())->get();
    }

    public function subscription(string|int $id): Subscription
    {
        $subscription = $this->subscriptionsQuery()->whereKey((int) $id)->first();

        if (! $subscription instanceof Subscription) {
            throw ApiException::notFound();
        }

        return $subscription;
    }

    /**
     * Changing an agreement: the user's own, or a team's with `manage
     * billing` in that team (the current one; another team's row is not
     * visible here at all).
     */
    public function authorizeChange(Subscription $subscription): void
    {
        if (! $this->mayChange($subscription)) {
            throw ApiException::make('forbidden', 403);
        }
    }

    /**
     * A team's agreement is changed by `manage billing` in **that** team,
     * named in the header. Having paid for it is not enough: the payer may
     * have left, and `manage billing` in a team of their own says nothing
     * about this one.
     */
    public function mayChange(Subscription $subscription): bool
    {
        $teamId = $this->teamIdOf($subscription);

        if ($teamId === null) {
            return true;
        }

        $team = $this->team();

        return $team !== null && (int) $team->getKey() === $teamId && $this->canManageTeam();
    }

    /** @return Builder<Payment> */
    protected function paymentsQuery(): Builder
    {
        return $this->visible(Payment::query()->where('status', Payment::STATUS_PAID));
    }

    /** @return Builder<Subscription> */
    protected function subscriptionsQuery(): Builder
    {
        return $this->visible(Subscription::query());
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query): Builder
    {
        $userId = (string) $this->user->id();
        $email = UserResource::verified($this->user) === true ? strtolower((string) $this->user->email()) : '';
        $team = $this->canViewTeam() ? $this->team() : null;

        return Brands::only($query, Brands::stampId())->where(function (Builder $query) use ($userId, $email, $team) {
            $query->where('meta->app_api_user_id', $userId);

            if ($email !== '') {
                $query->orWhere(fn (Builder $query) => $query
                    ->whereNull('meta->app_api_user_id')
                    ->whereRaw('lower(email) = ?', [$email]));
            }

            if ($team !== null) {
                $query->orWhere('meta->team_id', (int) $team->getKey());
            }
        });
    }

    protected function limit(): int
    {
        return max(1, (int) config('statamic-payments.portal.max_rows', 100));
    }

    protected function teamIdOf(Payment|Subscription $row): ?int
    {
        $id = data_get($row->meta, 'team_id');

        return is_numeric($id) ? (int) $id : null;
    }

    protected function scopeOf(Payment|Subscription $row): string
    {
        $teamId = $this->teamIdOf($row);
        $team = $this->team();

        return $teamId !== null && $team !== null && $teamId === (int) $team->getKey() && $this->canViewTeam() ? 'team' : 'user';
    }

    // Documents (statamic-invoices) ----------------------------------------------

    public static function documentsAvailable(): bool
    {
        return class_exists(self::INVOICE_MODEL) && interface_exists(self::PDF_RENDERER);
    }

    /**
     * Invoices and credit notes of the given payments, newest first.
     *
     * @param  list<int>  $paymentIds
     * @return Collection<int, Invoice>
     */
    public function documentsOf(array $paymentIds): Collection
    {
        if (! self::documentsAvailable() || $paymentIds === []) {
            return collect();
        }

        $model = self::INVOICE_MODEL;

        return collect($model::query()
            ->whereIn('payment_id', $paymentIds)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->all());
    }

    /** @return Collection<int, Invoice> */
    public function documents(): Collection
    {
        return $this->documentsOf($this->paymentsQuery()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function document(string|int $id): object
    {
        if (! self::documentsAvailable()) {
            throw ApiException::notFound();
        }

        $model = self::INVOICE_MODEL;
        $document = $model::query()->whereKey((int) $id)->first();

        // Visible exactly when its payment is.
        if ($document === null || $document->payment_id === null || ! $this->paymentsQuery()->whereKey((int) $document->payment_id)->exists()) {
            throw ApiException::notFound();
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public function presentDocument(object $document): array
    {
        $kind = (string) ($document->kind ?? 'invoice');

        return [
            'id' => (int) $document->getKey(),
            'number' => (string) $document->number,
            'kind' => $kind === 'credit_note' || $document->reverses_invoice_id !== null ? 'credit_note' : 'invoice',
            'reverses' => $document->reverses?->number,
            'payment_id' => (int) $document->payment_id,
            'issued_at' => self::iso($document->issued_at),
            'issued_at_display' => LocalTime::portalDate($document->issued_at),
            'gross_cent' => (int) $document->gross_cent,
            'currency' => (string) $document->currency,
            'amount' => Display::money((int) $document->gross_cent, (string) $document->currency),
            'download_path' => '/'.trim((string) config('app-api.routes.prefix', 'api/app'), '/').'/billing/documents/'.$document->getKey(),
        ];
    }

    // How they read --------------------------------------------------------------

    /**
     * @param  Collection<int, Invoice>|null  $documents  preloaded for a list
     * @return array<string, mixed>
     */
    public function presentPayment(Payment $payment, ?Collection $documents = null, bool $lines = false): array
    {
        $documents ??= $this->documentsOf([(int) $payment->getKey()]);
        $refunded = (int) ($payment->refunded_cent ?? 0);

        $row = [
            'id' => (int) $payment->getKey(),
            'product' => $payment->product,
            'name' => $this->nameOf($payment->product),
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => $payment->currency,
            'amount' => Display::money((int) $payment->amount_cent, $payment->currency),
            'status' => $payment->status,
            'paid_at' => self::iso($payment->paid_at),
            'paid_at_display' => LocalTime::portalDate($payment->paid_at),
            'refunded' => $refunded > 0,
            'refunded_cent' => $refunded,
            'refunded_label' => $refunded > 0 ? Anrede::trans('statamic-payments::portal.order_refunded') : null,
            'scope' => $this->scopeOf($payment),
            'team_id' => $this->teamIdOf($payment),
            'subscription_id' => $payment->subscription_id !== null ? (int) $payment->subscription_id : null,
            'documents' => $documents
                ->filter(fn ($document) => (int) $document->payment_id === (int) $payment->getKey())
                ->sortBy(fn ($document) => [$document->reverses_invoice_id !== null ? 1 : 0, (int) $document->getKey()])
                ->map(fn ($document) => $this->presentDocument($document))
                ->values()
                ->all(),
        ];

        if ($lines) {
            $row['lines'] = $payment->items->map(fn ($item) => [
                'product' => $item->product,
                'name' => $item->name,
                'quantity' => (int) ($item->quantity ?? 1),
                'amount_cent' => (int) $item->amount_cent,
                'amount' => Display::money((int) $item->amount_cent, $payment->currency),
            ])->values()->all();
        }

        return $row;
    }

    /**
     * An agreement as the portal lists it (`OrdersController::asRow()`),
     * with the texts it prints next to it.
     *
     * @return array<string, mixed>
     */
    public function presentSubscription(Subscription $subscription): array
    {
        $pauses = app(SubscriptionPauses::class);
        $gateway = $this->gatewayOf($subscription);
        $running = $subscription->isRunning() || $subscription->isClaimed();
        $mayChange = $this->mayChange($subscription);
        $paidUntil = self::paidUntil($subscription);
        $cancelHere = $this->mayCancelHere($subscription);
        $canChangeMethod = $subscription->isRunning()
            && $gateway instanceof MandateGateway
            && $gateway->supportsMandateUpdate()
            && $subscription->customer_reference !== '';

        $statusLabel = $subscription->isPaused()
            ? ($subscription->resumes_at
                ? Anrede::trans('statamic-payments::subscriptions.portal_paused_until', ['date' => LocalTime::portalDate($subscription->resumes_at)])
                : Anrede::trans('statamic-payments::subscriptions.portal_paused_open'))
            : Anrede::trans('statamic-payments::portal.status_'.$subscription->status);

        $remaining = $subscription->remaining();

        return [
            'id' => (int) $subscription->getKey(),
            'product' => $subscription->product,
            'name' => $this->nameOf($subscription->product),
            'status' => $subscription->status,
            'status_label' => $statusLabel,
            'live' => $subscription->isLive(),
            'running' => $running,
            'paused' => $subscription->isPaused(),
            'amount_cent' => $subscription->chargedCent(),
            'currency' => $subscription->currency,
            'amount' => Display::money($subscription->chargedCent(), $subscription->currency),
            'interval' => $subscription->interval,
            'rhythm' => Display::rhythm((string) $subscription->interval),
            'coupon' => Display::coupon($subscription) ?: null,
            'started_at' => self::iso($subscription->created_at),
            'started_at_display' => LocalTime::portalDate($subscription->created_at),
            'next_payment_at' => self::iso($subscription->next_payment_at),
            'next_payment_display' => LocalTime::portalDate($subscription->next_payment_at),
            'next_payment_label' => $subscription->isLive() && $subscription->next_payment_at
                ? Anrede::trans('statamic-payments::portal.subscription_next', ['date' => LocalTime::portalDate($subscription->next_payment_at)])
                : null,
            'paid_until' => self::iso($paidUntil),
            'paid_until_display' => LocalTime::portalDate($paidUntil),
            'cancelled_at' => self::iso($subscription->cancelled_at),
            'cancelled_at_display' => LocalTime::portalDate($subscription->cancelled_at),
            'ended_label' => ! $subscription->isLive() && ! $subscription->isPaused() && $subscription->cancelled_at
                ? Anrede::trans('statamic-payments::portal.subscription_ended', ['date' => LocalTime::portalDate($subscription->cancelled_at)])
                : null,
            'resumes_at' => self::iso($subscription->resumes_at),
            'resumes_at_display' => LocalTime::portalDate($subscription->resumes_at),
            'remaining' => $remaining,
            'remaining_label' => $remaining !== null
                ? Anrede::trans('statamic-payments::portal.subscription_remaining', ['count' => $remaining])
                : null,
            'payment_method' => $this->paymentMethodOf($subscription),
            'scope' => $this->scopeOf($subscription),
            'team_id' => $this->teamIdOf($subscription),
            'actions' => [
                'cancel' => $mayChange && $running && $cancelHere,
                'cancel_elsewhere_url' => $running && ! $cancelHere ? self::cancellationUrl() : null,
                'pause' => $mayChange && $pauses->portalMayPause($subscription),
                'resume' => $mayChange && $subscription->isPaused() && $pauses->portalAllows($subscription),
                'switch' => $mayChange && app(SubscriptionSwitches::class)->targetsFor($subscription, portal: true) !== [],
                'change_method' => $mayChange && $canChangeMethod,
            ],
            'method_note' => $canChangeMethod && $gateway instanceof MandateGateway
                ? Anrede::trans('statamic-payments::portal.method_note', ['amount' => Display::money($gateway->mandateVerificationCent(), $subscription->currency)])
                : null,
        ];
    }

    /**
     * The card on file, masked, from the agreement's latest payment that
     * noted one. Only what the provider handed back for display: a label
     * and the last four digits.
     *
     * @return array{label: string|null, last4: string, expires_at: string|null, display: string}|null
     */
    protected function paymentMethodOf(Subscription $subscription): ?array
    {
        $payment = $subscription->payments()
            ->whereNotNull('card_last4')
            ->where('card_last4', '!=', '')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        $label = is_string($payment->card_label) && $payment->card_label !== '' ? $payment->card_label : null;
        $last4 = (string) $payment->card_last4;

        return [
            'label' => $label,
            'last4' => $last4,
            'expires_at' => $subscription->card_expires_at?->toDateString(),
            'display' => trim(($label ?? '').' •••• '.$last4),
        ];
    }

    public function gatewayOf(Subscription $subscription): ?object
    {
        try {
            return app(Gateways::class)->for($subscription);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The portal's rule (`PortalController::mayCancelHere()`): the product's
     * `portal_cancel`, else `portal.self_cancel`. Off switches off the
     * button here, never the statutory way without login.
     */
    public function mayCancelHere(Subscription $subscription): bool
    {
        $entry = app(Catalogue::class)->find($subscription->product) ?? [];

        return is_bool($entry['portal_cancel'] ?? null)
            ? $entry['portal_cancel']
            : (bool) config('statamic-payments.portal.self_cancel', true);
    }

    public function nameOf(string $handle): string
    {
        $name = (app(Catalogue::class)->find($handle) ?? [])['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $handle;
    }

    /**
     * Paid until: the next charge, else the end of the period of the last
     * paid payment, and only when that lies ahead. The portal's rule
     * (`CancellationController::paidUntil()`).
     */
    public static function paidUntil(Subscription $subscription): ?Carbon
    {
        $until = $subscription->next_payment_at ?? $subscription->paidThroughAt();

        return $until !== null && $until->isFuture() ? Carbon::instance($until) : null;
    }

    public static function iso(mixed $moment): ?string
    {
        return $moment instanceof \DateTimeInterface ? LocalTime::of(Carbon::instance($moment))?->toIso8601String() : null;
    }

    public static function cancellationUrl(): ?string
    {
        return config('statamic-payments.cancellation.enabled', true) && app('router')->has('statamic-payments.cancellation.form')
            ? route('statamic-payments.cancellation.form')
            : null;
    }

    public static function withdrawalUrl(): ?string
    {
        return config('statamic-payments.withdrawal.enabled', true) && app('router')->has('statamic-payments.withdrawal.form')
            ? route('statamic-payments.withdrawal.form')
            : null;
    }
}
