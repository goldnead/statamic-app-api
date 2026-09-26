<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\Fakes\FakeSubscriptionGateway;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Support\CancellationOutcome;
use Goldnead\StatamicPayments\Support\Cancellations;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * The cancellation in the app, after K2 on Staging (26.09.2026).
 *
 * - „Beendet am 26.09.2026" (the day of the cancellation) stood next to
 *   „Bezahlt bis 26.09.2027". The contract runs to the end of the paid term:
 *   „Gekündigt, läuft bis …" until then, „Beendet am <end of term>" after.
 *   The same sentence the portal builds (`Portal\Display::ending()`).
 * - The sequence is payments' own (`Support\Cancellations`), not a copy.
 * - A team's agreement: the confirmation also goes to the team's billing
 *   address, when there is one and it is another
 *   (`billing.cancellation_copy_to_team`, on by default).
 */
class BillingCancellationTest extends TestCase
{
    protected FakeSubscriptionGateway $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeSubscriptionGateway;
        $this->app->instance(PaymentGateway::class, $this->provider);

        config()->set('statamic-payments.display_timezone', 'Europe/Berlin');
        $this->app->setLocale('de');
        config()->set('statamic-payments.products.jahr', ['name' => 'Chor-Jahr', 'amount_cent' => 7900, 'interval' => '12 months']);

        $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'UTC'));
        Mail::fake();
    }

    /** @param  array<string, mixed>  $meta */
    protected function annual(array $meta): Subscription
    {
        static $n = 0;
        $n++;

        $sub = Subscription::query()->forceCreate([
            'provider' => 'mollie',
            'provider_id' => 'sub_jahr_'.$n,
            'customer_reference' => 'cst_jahr',
            'product' => 'jahr',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'interval' => '12 months',
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => Carbon::parse('2027-09-26 09:00:00', 'UTC'),
            'next_payment_at' => Carbon::parse('2027-09-26 09:00:00', 'UTC'),
            'email' => 'nobody@example.com',
            'meta' => $meta,
        ]);

        Payment::query()->forceCreate([
            'provider' => 'mollie',
            'provider_id' => 'tr_jahr_'.$n,
            'product' => 'jahr',
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'nobody@example.com',
            'paid_at' => Carbon::parse('2026-09-26 09:00:00', 'UTC'),
            'subscription_id' => $sub->id,
            'meta' => $meta,
        ]);

        return $sub;
    }

    /** @return array{0: Team, 1: UserContract} */
    protected function team(?string $billingEmail): array
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        $admin = $this->makeUser('admin@example.com');
        Teams::addMember($team, $admin, 'admin');

        if ($billingEmail !== null) {
            $team->forceFill(['billing' => ['email' => $billingEmail]])->save();
        }

        return [$team, $admin];
    }

    #[Test]
    public function a_cancelled_agreement_runs_to_the_end_of_the_paid_term(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->annual(['app_api_user_id' => (string) $sina->id()]);
        $this->actingAs($sina);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk()
            ->assertJsonPath('subscription.paid_until_display', '26.09.2027')
            ->assertJsonPath('subscription.ended_label', 'Gekündigt, läuft bis 26.09.2027');

        $this->api('GET', "billing/subscriptions/{$sub->id}")->assertOk()
            ->assertJsonPath('subscription.ended_label', 'Gekündigt, läuft bis 26.09.2027');
    }

    #[Test]
    public function after_the_term_it_ended_on_the_last_day_of_the_term(): void
    {
        $sina = $this->makeUser('sina@example.com');
        $sub = $this->annual(['app_api_user_id' => (string) $sina->id()]);
        $this->actingAs($sina);
        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk();

        $this->travelTo(Carbon::parse('2027-10-01 10:00:00', 'UTC'));

        $this->api('GET', "billing/subscriptions/{$sub->id}")->assertOk()
            ->assertJsonPath('subscription.ended_label', 'Beendet am 26.09.2027')
            ->assertJsonPath('subscription.ends_at', '2027-09-26T11:00:00+02:00');
    }

    #[Test]
    public function the_sequence_is_payments_own(): void
    {
        $spy = new class($this->app->make(Subscriptions::class)) extends Cancellations
        {
            /** @var list<array{0: int, 1: string|null, 2: array<int, string|null>}> */
            public array $calls = [];

            public function cancel(Subscription $subscription, ?string $email = null, array $copies = []): CancellationOutcome
            {
                $this->calls[] = [(int) $subscription->id, $email, $copies];

                return parent::cancel($subscription, $email, $copies);
            }
        };
        $this->app->instance(Cancellations::class, $spy);

        $sina = $this->makeUser('sina@example.com');
        $sub = $this->annual(['app_api_user_id' => (string) $sina->id()]);
        $this->actingAs($sina);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true])->assertOk()
            ->assertJsonPath('mail_sent', true);

        $this->assertSame([[(int) $sub->id, 'sina@example.com', []]], $spy->calls);
        Mail::assertSent(CancellationConfirmed::class, 1);
    }

    #[Test]
    public function a_team_agreement_sends_a_copy_to_the_teams_billing_address(): void
    {
        [$team, $admin] = $this->team('rechnung@kammerchor.example');
        $sub = $this->annual(['team_id' => $team->id]);
        $this->actingAs($admin);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true], ['X-Team-ID' => (string) $team->id])->assertOk()
            ->assertJsonPath('mail_sent', true)
            ->assertJsonPath('copied_to', ['rechnung@kammerchor.example']);

        Mail::assertSent(CancellationConfirmed::class, 2);
        Mail::assertSent(CancellationConfirmed::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        Mail::assertSent(CancellationConfirmed::class, fn ($mail) => $mail->hasTo('rechnung@kammerchor.example'));
    }

    #[Test]
    public function no_copy_when_the_billing_address_is_the_same_missing_or_switched_off(): void
    {
        [$team, $admin] = $this->team('ADMIN@example.com');
        $sub = $this->annual(['team_id' => $team->id]);
        $this->actingAs($admin);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true], ['X-Team-ID' => (string) $team->id])->assertOk()
            ->assertJsonPath('copied_to', []);
        Mail::assertSent(CancellationConfirmed::class, 1);

        // Switched off.
        Mail::fake();
        config()->set('app-api.billing.cancellation_copy_to_team', false);
        $team->forceFill(['billing' => ['email' => 'rechnung@kammerchor.example']])->save();
        $second = $this->annual(['team_id' => $team->id]);

        $this->api('POST', "billing/subscriptions/{$second->id}/cancel", ['confirmed' => true], ['X-Team-ID' => (string) $team->id])->assertOk();
        Mail::assertSent(CancellationConfirmed::class, 1);
        Mail::assertNotSent(CancellationConfirmed::class, fn ($mail) => $mail->hasTo('rechnung@kammerchor.example'));
    }

    #[Test]
    public function a_personal_agreement_sends_no_copy_to_any_team(): void
    {
        [$team, $admin] = $this->team('rechnung@kammerchor.example');
        $sub = $this->annual(['app_api_user_id' => (string) $admin->id()]);
        $this->actingAs($admin);

        $this->api('POST', "billing/subscriptions/{$sub->id}/cancel", ['confirmed' => true], ['X-Team-ID' => (string) $team->id])->assertOk();

        Mail::assertSent(CancellationConfirmed::class, 1);
    }
}
