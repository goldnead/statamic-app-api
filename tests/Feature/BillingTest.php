<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\Accounts\Facades\Accounts;
use Goldnead\AppApi\Tests\Fakes\FakeSubscriptionGateway;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * The customer area in the app: orders, documents, subscriptions and the
 * cancellation, against the real statamic-payments and statamic-invoices.
 * Only the provider (and the PDF engine) are stand-ins.
 */
class BillingTest extends TestCase
{
    protected FakeSubscriptionGateway $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeSubscriptionGateway;
        $this->app->instance(PaymentGateway::class, $this->provider);

        $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-fake '.$invoice->number;
            }
        });

        config()->set('statamic-payments.display_timezone', 'Europe/Berlin');
        $this->app->setLocale('de');
        config()->set('teams.roles.kasse', ['label' => 'Kasse', 'permissions' => ['view billing']]);
        config()->set('statamic-payments.products.abo', ['name' => 'Chor-Abo', 'amount_cent' => 900, 'interval' => '1 month', 'grants' => ['choirlive-pro']]);
    }

    // Fixtures ---------------------------------------------------------------

    /** @param  array<string, mixed>  $attributes */
    protected function payment(array $attributes = []): Payment
    {
        static $n = 0;
        $n++;

        return Payment::query()->forceCreate(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'tr_paid_'.$n,
            'product' => 'lifetime',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'nobody@example.com',
            'paid_at' => Carbon::parse('2026-09-01 22:30:00', 'UTC'),
            'meta' => [],
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function subscription(array $attributes = []): Subscription
    {
        static $n = 0;
        $n++;

        return Subscription::query()->forceCreate(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'sub_'.$n,
            'customer_reference' => 'cst_'.$n,
            'product' => 'abo',
            'amount_cent' => 900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'next_payment_at' => now()->addDays(10),
            'email' => 'nobody@example.com',
            'meta' => [],
        ], $attributes));
    }

    protected function mine(UserContract $user): array
    {
        return ['app_api_user_id' => (string) $user->id()];
    }

    protected function invoiceFor(Payment $payment, string $number, string $kind = Invoice::KIND_INVOICE, ?Invoice $reverses = null): Invoice
    {
        return Invoice::query()->forceCreate([
            'number' => $number,
            'kind' => $kind,
            'payment_id' => $payment->getKey(),
            'reverses_invoice_id' => $reverses?->getKey(),
            'issued_at' => now(),
            'currency' => 'EUR',
            'net_cent' => 6639,
            'tax_cent' => 1261,
            'gross_cent' => 7900,
        ]);
    }

    /** @return array{0: UserContract, 1: Team, 2: UserContract} owner, team, member with the given role */
    protected function teamWith(string $role): array
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        $member = $this->makeUser('member@example.com');
        Teams::addMember($team, $member, $role);

        return [$owner, $team, $member];
    }

    protected function teamHeader(Team $team): array
    {
        return ['X-Team-ID' => (string) $team->id];
    }

    // Overview ---------------------------------------------------------------

    #[Test]
    public function the_billing_endpoints_need_a_session(): void
    {
        $this->assertError($this->api('GET', 'billing'), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'billing/subscriptions'), 401, 'unauthenticated');
        $this->assertError($this->api('POST', 'billing/subscriptions/1/cancel', ['confirmed' => true]), 401, 'unauthenticated');
    }

    #[Test]
    public function the_overview_shows_the_users_paid_orders_and_nothing_of_anybody_else(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $own = $this->payment($this->forUser($sina));
        $this->payment(['meta' => $this->mine($sina), 'status' => Payment::STATUS_OPEN]);
        $this->payment(['email' => 'sina@example.com']); // unconfirmed address: not hers yet
        $this->payment(['meta' => ['app_api_user_id' => 'someone-else']]);
        $this->actingAs($sina);

        $response = $this->api('GET', 'billing')->assertOk();

        $this->assertSame([$own->id], array_column($response->json('payments'), 'id'));
        $response->assertJsonPath('payments.0.name', 'Lifetime')
            ->assertJsonPath('payments.0.amount', '79,00 EUR')
            ->assertJsonPath('payments.0.scope', 'user')
            // 22:30 UTC is the next day in Berlin: the display zone of payments.
            ->assertJsonPath('payments.0.paid_at_display', '02.09.2026')
            ->assertJsonPath('team', null);
    }

    protected function forUser(UserContract $user): array
    {
        return ['meta' => $this->mine($user)];
    }

    #[Test]
    public function a_confirmed_address_counts_like_the_portal(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $byMail = $this->payment(['email' => 'Sina@Example.com']);
        Accounts::verification()->markVerified($sina);
        $this->actingAs($sina);

        $this->assertSame([$byMail->id], array_column($this->api('GET', 'billing')->json('payments'), 'id'));
    }

    #[Test]
    public function the_texts_follow_the_anrede_of_payments(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $sie = $this->api('GET', "billing/subscriptions/{$sub->id}/cancel")->json('confirmation.intro');
        config()->set('statamic-payments.anrede', 'du');
        $du = $this->api('GET', "billing/subscriptions/{$sub->id}/cancel")->json('confirmation.intro');

        $this->assertStringContainsString('Sie', $sie);
        $this->assertNotSame($sie, $du);
    }

    #[Test]
    public function team_orders_show_with_view_billing_only(): void
    {
        [$owner, $team, $member] = $this->teamWith('member');
        $teamOrder = $this->payment(['meta' => ['team_id' => $team->id, 'app_api_user_id' => (string) $owner->id()]]);

        $this->actingAs($member);
        $response = $this->api('GET', 'billing', [], $this->teamHeader($team))->assertOk();
        $this->assertSame([], $response->json('payments'));
        $response->assertJsonPath('team.can_view_billing', false);

        $this->actingAs($owner);
        $response = $this->api('GET', 'billing', [], $this->teamHeader($team))->assertOk();
        $this->assertSame([$teamOrder->id], array_column($response->json('payments'), 'id'));
        $response->assertJsonPath('payments.0.scope', 'team')->assertJsonPath('team.can_manage_billing', true);
    }

    #[Test]
    public function a_team_the_user_is_not_in_is_403(): void
    {
        [, $team] = $this->teamWith('member');
        $this->actingAs($this->makeUser('fremd@example.com'));

        $this->assertError($this->api('GET', 'billing', [], $this->teamHeader($team)), 403, 'not_member');
    }

    #[Test]
    public function the_overview_names_the_statutory_ways_out(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $response = $this->api('GET', 'billing')->assertOk();

        $this->assertStringContainsString('/!/statamic-payments/kuendigung', (string) $response->json('links.cancellation_url'));
        $this->assertStringContainsString('/!/statamic-payments/widerruf', (string) $response->json('links.withdrawal_url'));
    }

    // Orders and documents ---------------------------------------------------

    #[Test]
    public function one_order_with_its_documents_including_the_credit_note(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $payment = $this->payment($this->forUser($sina) + ['refunded_cent' => 7900]);
        $invoice = $this->invoiceFor($payment, 'RE-1');
        $this->invoiceFor($payment, 'RE-2', Invoice::KIND_CREDIT_NOTE, $invoice);
        $this->actingAs($sina);

        $this->api('GET', "billing/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('payment.refunded', true)
            ->assertJsonPath('payment.documents.0.number', 'RE-1')
            ->assertJsonPath('payment.documents.1.kind', 'credit_note')
            ->assertJsonPath('payment.documents.1.reverses', 'RE-1');

        $list = $this->api('GET', 'billing/documents')->assertOk();
        $this->assertSame(['RE-2', 'RE-1'], array_column($list->json('data'), 'number'));
    }

    #[Test]
    public function a_document_downloads_as_pdf(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $invoice = $this->invoiceFor($this->payment($this->forUser($sina)), 'RE-7');
        $this->actingAs($sina);

        $response = $this->api('GET', "billing/documents/{$invoice->id}")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('RE-7.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('%PDF-fake RE-7', $response->getContent());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function readEndpoints(): array
    {
        return [
            'order' => ['GET', 'billing/payments/{payment}'],
            'document' => ['GET', 'billing/documents/{document}'],
            'subscription' => ['GET', 'billing/subscriptions/{subscription}'],
            'cancel info' => ['GET', 'billing/subscriptions/{subscription}/cancel'],
            'pause info' => ['GET', 'billing/subscriptions/{subscription}/pause'],
            'switch info' => ['GET', 'billing/subscriptions/{subscription}/switch'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function writeEndpoints(): array
    {
        return [
            'cancel' => ['POST', 'billing/subscriptions/{subscription}/cancel'],
            'pause' => ['POST', 'billing/subscriptions/{subscription}/pause'],
            'resume' => ['POST', 'billing/subscriptions/{subscription}/resume'],
            'switch' => ['POST', 'billing/subscriptions/{subscription}/switch'],
            'payment method' => ['POST', 'billing/subscriptions/{subscription}/payment-method'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function allEndpoints(): array
    {
        return self::readEndpoints() + self::writeEndpoints();
    }

    /** @return array{payment: int, document: int, subscription: int} */
    protected function rowsOf(array $meta, string $email = 'nobody@example.com'): array
    {
        $payment = $this->payment(['meta' => $meta, 'email' => $email]);

        return [
            'payment' => $payment->id,
            'document' => $this->invoiceFor($payment, 'RE-'.$payment->id)->id,
            'subscription' => $this->subscription(['meta' => $meta, 'email' => $email])->id,
        ];
    }

    protected function path(string $uri, array $ids): string
    {
        return str_replace(['{payment}', '{document}', '{subscription}'], [$ids['payment'], $ids['document'], $ids['subscription']], $uri);
    }

    #[Test]
    #[DataProvider('allEndpoints')]
    public function another_users_rows_are_404(string $method, string $uri): void
    {
        $sina = $this->makeUser('sina@example.com');
        $ids = $this->rowsOf($this->mine($sina), 'sina@example.com');
        Accounts::verification()->markVerified($sina);

        // Bob registered with Sina's address but never confirmed it.
        $this->actingAs($this->makeUser('bob@example.com'));

        $this->assertError($this->api($method, $this->path($uri, $ids), ['confirmed' => true, 'to' => 'lifetime']), 404, 'not_found');
        $this->assertSame([], $this->provider->cancelled);
        $this->assertSame([], $this->provider->mandates);
    }

    #[Test]
    #[DataProvider('allEndpoints')]
    public function a_team_row_is_404_for_a_member_without_view_billing(string $method, string $uri): void
    {
        [, $team, $member] = $this->teamWith('member');
        $ids = $this->rowsOf(['team_id' => $team->id]);
        $this->actingAs($member);

        $this->assertError($this->api($method, $this->path($uri, $ids), ['confirmed' => true], $this->teamHeader($team)), 404, 'not_found');
        $this->assertSame([], $this->provider->cancelled);
    }

    #[Test]
    #[DataProvider('allEndpoints')]
    public function a_team_row_is_404_from_another_team(string $method, string $uri): void
    {
        [$owner, $team] = $this->teamWith('member');
        $ids = $this->rowsOf(['team_id' => $team->id]);

        $other = Teams::create('Anderer Chor', $owner);
        $this->actingAs($owner);

        // The owner of both, asking with the other team's header: the row
        // belongs to the first team, not to this request.
        $this->assertError($this->api($method, $this->path($uri, $ids), ['confirmed' => true], $this->teamHeader($other)), 404, 'not_found');
    }

    #[Test]
    #[DataProvider('allEndpoints')]
    public function a_foreign_team_header_is_403(string $method, string $uri): void
    {
        [, $team] = $this->teamWith('member');
        $ids = $this->rowsOf(['team_id' => $team->id]);
        $this->actingAs($this->makeUser('fremd@example.com'));

        $this->assertError($this->api($method, $this->path($uri, $ids), ['confirmed' => true], $this->teamHeader($team)), 403, 'not_member');
    }

    #[Test]
    #[DataProvider('writeEndpoints')]
    public function viewing_team_billing_is_not_managing_it(string $method, string $uri): void
    {
        [, $team, $kasse] = $this->teamWith('kasse');
        $ids = $this->rowsOf(['team_id' => $team->id]);
        $this->actingAs($kasse);

        $this->api('GET', $this->path('billing/subscriptions/{subscription}', $ids), [], $this->teamHeader($team))->assertOk();
        $this->assertError($this->api($method, $this->path($uri, $ids), ['confirmed' => true], $this->teamHeader($team)), 403, 'forbidden');
        $this->assertSame([], $this->provider->cancelled);
    }

    // Subscriptions ----------------------------------------------------------

    #[Test]
    public function a_subscription_reads_as_in_the_portal(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina), 'card_expires_at' => '2027-03-31']);
        $this->payment(['meta' => $this->mine($sina), 'subscription_id' => $sub->id, 'product' => 'abo', 'amount_cent' => 900, 'card_label' => 'Mastercard', 'card_last4' => '4444']);
        $this->actingAs($sina);

        $this->api('GET', 'billing/subscriptions')->assertOk()
            ->assertJsonPath('data.0.id', $sub->id)
            ->assertJsonPath('data.0.name', 'Chor-Abo')
            ->assertJsonPath('data.0.status_label', 'Läuft')
            ->assertJsonPath('data.0.amount', '9,00 EUR')
            ->assertJsonPath('data.0.rhythm', 'monatlich')
            ->assertJsonPath('data.0.paid_until', $sub->next_payment_at->copy()->setTimezone('Europe/Berlin')->toIso8601String())
            ->assertJsonPath('data.0.payment_method.last4', '4444')
            ->assertJsonPath('data.0.payment_method.display', 'Mastercard •••• 4444')
            ->assertJsonPath('data.0.actions.cancel', true)
            ->assertJsonPath('data.0.actions.change_method', true);
    }

    #[Test]
    public function the_cancel_confirmation_shows_what_the_portal_shows_before_the_button(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $response = $this->api('GET', "billing/subscriptions/{$sub->id}/cancel")->assertOk()
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('confirmation.contract', 'Chor-Abo')
            ->assertJsonPath('confirmation.button_label', 'Jetzt kündigen');

        $this->assertStringContainsString('9,00 EUR', (string) $response->json('confirmation.price'));
        $this->assertStringContainsString($sub->next_payment_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'), (string) $response->json('confirmation.effect'));
    }

    #[Test]
    public function cancelling_needs_the_confirmation(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/cancel"), 422, 'confirmation_required');
        $this->assertSame([], $this->provider->cancelled);
    }

    #[Test]
    public function cancelling_goes_through_payments_confirms_by_mail_and_fires_the_same_event(): void
    {
        Mail::fake();
        Event::fake([SubscriptionCancelled::class]);
        config()->set('statamic.users.elevated_sessions_enabled', true);

        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $until = $sub->next_payment_at->copy();
        $this->payment(['meta' => $this->mine($sina), 'subscription_id' => $sub->id]);
        $this->actingAs($sina);

        // § 312k: no elevated session in the way, the login is enough.
        $response = $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('mail_sent', true)
            ->assertJsonPath('subscription.status', Subscription::STATUS_CANCELLED)
            ->assertJsonPath('paid_until', $until->copy()->setTimezone('Europe/Berlin')->toIso8601String());

        $this->assertSame([$sub->provider_id], $this->provider->cancelled);
        $this->assertIsString($response->json('message'));
        Event::assertDispatched(SubscriptionCancelled::class);
        Mail::assertSent(CancellationConfirmed::class, fn (CancellationConfirmed $mail) => $mail->hasTo('sina@example.com') && $mail->paidUntil?->equalTo($until));
        $this->assertTrue(Payment::query()->where('subscription_id', $sub->id)->first()->communications()->where('kind', 'cancellation_confirmation')->exists());
    }

    #[Test]
    public function a_second_cancel_is_the_same_confirmation_without_a_second_mail(): void
    {
        Mail::fake();
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk();
        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('already', true);

        Mail::assertSentCount(1);
        $this->assertCount(1, $this->provider->cancelled);
    }

    #[Test]
    public function a_provider_that_refuses_leaves_the_contract_running(): void
    {
        Mail::fake();
        $this->provider->refuseCancel = true;
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true]), 503, 'cancel_failed');
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_product_that_keeps_cancelling_out_of_the_portal_points_to_the_statutory_way(): void
    {
        config()->set('statamic-payments.products.abo.portal_cancel', false);
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $response = $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true]), 409, 'cancel_elsewhere');
        $this->assertStringContainsString('/!/statamic-payments/kuendigung', (string) $response->json('error.details.cancellation_url'));
    }

    #[Test]
    public function a_team_manager_cancels_the_team_subscription(): void
    {
        Mail::fake();
        [, $team, $admin] = $this->teamWith('admin');
        $sub = $this->subscription(['meta' => ['team_id' => $team->id]]);
        $this->actingAs($admin);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true], $this->teamHeader($team))->assertOk()
            ->assertJsonPath('cancelled', true);

        Mail::assertSent(CancellationConfirmed::class, fn ($mail) => $mail->hasTo('member@example.com'));
    }

    #[Test]
    public function new_subscriptions_remember_the_user_who_bought_them(): void
    {
        $this->assertContains('app_api_user_id', array_keys(Subscriptions::inheritedMeta(
            new Payment(['meta' => ['app_api_user_id' => '7']])
        )));
    }

    // Pause, switch, payment method -----------------------------------------

    #[Test]
    public function pausing_is_refused_where_the_product_does_not_allow_it(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->api('GET', 'billing/subscriptions')->assertJsonPath('data.0.actions.pause', false);
        $this->assertError($this->api('GET', "billing/subscriptions/{$sub->id}/pause"), 409, 'pause_unavailable');
        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/pause"), 409, 'pause_unavailable');
        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/resume"), 409, 'pause_unavailable');
    }

    #[Test]
    public function pausing_with_a_date_in_the_past_is_422(): void
    {
        config()->set('statamic-payments.portal.allow_pause', true);
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->api('GET', "billing/subscriptions/{$sub->id}/pause")->assertOk()
            ->assertJsonPath('min_date', Carbon::tomorrow()->toDateString());
        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/pause", ['resume_on' => '2020-01-01']), 422, 'pause_date_invalid');
    }

    #[Test]
    public function pausing_goes_through_payments(): void
    {
        config()->set('statamic-payments.portal.allow_pause', true);
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->api('POST', "billing/subscriptions/{$sub->id}/pause")->assertOk()
            ->assertJsonPath('subscription.paused', true)
            ->assertJsonPath('subscription.actions.resume', true);

        $this->assertTrue($sub->fresh()->isPaused());
    }

    #[Test]
    public function switching_without_targets_is_409(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->assertError($this->api('GET', "billing/subscriptions/{$sub->id}/switch"), 409, 'switch_unavailable');
        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/switch", ['to' => 'lifetime']), 409, 'switch_unavailable');
    }

    #[Test]
    public function changing_the_payment_method_answers_with_the_provider_url(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $response = $this->api('POST', "billing/subscriptions/{$sub->id}/payment-method", ['return_url' => '/app/kauf'])->assertOk()
            ->assertJsonPath('url', 'https://pay.test/mandate/1')
            ->assertJsonPath('verification', '0,01 EUR');

        $this->assertSame('http://localhost/app/kauf', $this->provider->mandates[0]['redirectUrl']);
        $this->assertSame($sub->customer_reference, $this->provider->mandates[0]['customer']);
        $this->assertIsString($response->json('note'));
    }

    #[Test]
    public function an_outside_return_url_is_not_passed_to_the_provider(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina)]);
        $this->actingAs($sina);

        $this->api('POST', "billing/subscriptions/{$sub->id}/payment-method", ['return_url' => 'https://evil.test/'])->assertOk();

        $this->assertStringStartsWith('http://localhost', $this->provider->mandates[0]['redirectUrl']);
    }

    #[Test]
    public function an_ended_subscription_offers_no_payment_method_change(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->subscription(['meta' => $this->mine($sina), 'status' => Subscription::STATUS_CANCELLED]);
        $this->actingAs($sina);

        $this->assertError($this->api('POST', "billing/subscriptions/{$sub->id}/payment-method"), 409, 'method_unavailable');
    }
}
