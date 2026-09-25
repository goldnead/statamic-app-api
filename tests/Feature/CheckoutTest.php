<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\Accounts\Facades\Accounts;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\Teams\Facades\Teams;
use PHPUnit\Framework\Attributes\Test;

/**
 * The checkout against the real statamic-payments (and statamic-offers);
 * only the provider is the fake from TestCase.
 */
class CheckoutTest extends TestCase
{
    #[Test]
    public function the_checkout_endpoints_need_a_session(): void
    {
        $this->assertError($this->buy(['product' => 'lifetime', 'confirmed' => true]), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'checkout/1'), 401, 'unauthenticated');
        $this->assertError($this->api('POST', 'portal'), 401, 'unauthenticated');
    }

    #[Test]
    public function a_purchase_answers_with_the_provider_url_instead_of_a_redirect(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $response = $this->buy(['product' => 'lifetime', 'confirmed' => true])
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://pay.test/checkout/1')
            ->assertJsonPath('reused', false)
            ->assertJsonPath('payment.product', 'lifetime')
            ->assertJsonPath('payment.amount_cent', 7900);

        $payment = Payment::find($response->json('payment.id'));
        $this->assertSame('sina@example.com', $payment->email);
        $this->assertNotNull($payment->consent_at);
        $this->assertNotSame('', (string) $payment->consent_text);
    }

    #[Test]
    public function a_double_click_answers_with_the_same_checkout_and_creates_one_payment(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $first = $this->buy(['product' => 'lifetime', 'confirmed' => true])->assertCreated();
        $second = $this->buy(['product' => 'lifetime', 'confirmed' => true])->assertOk();

        $this->assertSame($first->json('checkout_url'), $second->json('checkout_url'));
        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertTrue($second->json('reused'));
        $this->assertSame(1, Payment::count());
        $this->assertCount(1, $this->gateway->created);
    }

    #[Test]
    public function a_paid_checkout_is_not_handed_out_again(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $id = $this->buy(['product' => 'lifetime', 'confirmed' => true])->json('payment.id');
        Payment::whereKey($id)->update(['status' => Payment::STATUS_PAID]);

        $this->buy(['product' => 'lifetime', 'confirmed' => true])->assertCreated()->assertJsonPath('reused', false);
        $this->assertSame(2, Payment::count());
    }

    #[Test]
    public function different_idempotency_keys_are_different_purchases(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->buy(['product' => 'lifetime', 'confirmed' => true], ['Idempotency-Key' => 'a'])->assertCreated();
        $this->buy(['product' => 'lifetime', 'confirmed' => true], ['Idempotency-Key' => 'a'])->assertOk();
        $this->buy(['product' => 'lifetime', 'confirmed' => true], ['Idempotency-Key' => 'b'])->assertCreated();

        $this->assertSame(2, Payment::count());
    }

    #[Test]
    public function without_the_order_checkbox_nothing_is_started(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->buy(['product' => 'lifetime']), 422, 'consent_required')
            ->assertJsonPath('error.field', 'confirmed');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function an_unknown_product_is_404(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->buy(['product' => 'gibt-es-nicht', 'confirmed' => true]), 404, 'product_not_found');
    }

    #[Test]
    public function a_provider_that_cannot_be_reached_is_503(): void
    {
        $this->gateway->fail = true;
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->buy(['product' => 'lifetime', 'confirmed' => true]), 503, 'provider_unavailable');
    }

    #[Test]
    public function a_team_purchase_names_the_team_as_buyer(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        Teams::update($team, ['billing' => ['company' => 'Kammerchor e.V.', 'email' => 'kasse@chor.test', 'line1' => 'Weg 1', 'city' => 'Köln', 'country' => 'DE']]);
        $this->actingAs($owner);

        $response = $this->buy(['product' => 'chortarif', 'for' => 'team', 'confirmed' => true], ['X-Team-ID' => (string) $team->id])
            ->assertCreated()
            ->assertJsonPath('payment.team_id', $team->id);

        $payment = Payment::find($response->json('payment.id'));
        $this->assertSame('kasse@chor.test', $payment->email);
        $this->assertSame(['type' => 'team', 'id' => (string) $team->id], $payment->meta['entitlement_subject']);
    }

    #[Test]
    public function a_member_without_billing_rights_may_not_buy_for_the_team(): void
    {
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));
        $member = $this->makeUser('member@example.com');
        Teams::addMember($team, $member);
        $this->actingAs($member);

        $this->assertError($this->buy(['product' => 'chortarif', 'for' => 'team', 'confirmed' => true], ['X-Team-ID' => (string) $team->id]), 403, 'forbidden');
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_foreign_team_is_403_and_no_team_is_422(): void
    {
        $team = Teams::create('Anderer Chor', $this->makeUser('owner@example.com'));
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->buy(['product' => 'chortarif', 'for' => 'team', 'confirmed' => true], ['X-Team-ID' => (string) $team->id]), 403, 'not_member');
        $this->assertError($this->buy(['product' => 'chortarif', 'for' => 'team', 'confirmed' => true]), 422, 'team_required');
    }

    #[Test]
    public function the_state_of_an_own_checkout_is_readable_and_a_strangers_is_404(): void
    {
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);
        $id = $this->buy(['product' => 'lifetime', 'confirmed' => true])->json('payment.id');

        $this->api('GET', 'checkout/'.$id)->assertOk()->assertJsonPath('payment.id', $id)->assertJsonPath('payment.paid', false);

        $this->actingAs($this->makeUser('fremd@example.com'));
        $this->assertError($this->api('GET', 'checkout/'.$id), 404, 'not_found');
    }

    #[Test]
    public function an_offer_is_bought_through_the_offers_basket(): void
    {
        Offer::create(['handle' => 'lifetime-50', 'name' => 'Lifetime', 'product' => 'lifetime', 'amount_cent' => 7900, 'quantity_limit' => 50]);
        $this->actingAs($this->makeUser('sina@example.com'));

        $response = $this->buy(['offer' => 'lifetime-50', 'confirmed' => true])->assertCreated();

        $payment = Payment::find($response->json('payment.id'));
        $this->assertStringStartsWith('offer:lifetime-50', (string) $payment->product);
        $this->assertArrayHasKey('withdrawal', $payment->meta);
    }

    #[Test]
    public function a_sold_out_offer_is_409(): void
    {
        Offer::create(['handle' => 'lifetime-50', 'name' => 'Lifetime', 'product' => 'lifetime', 'amount_cent' => 7900, 'quantity_limit' => 0]);
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->buy(['offer' => 'lifetime-50', 'confirmed' => true]), 409, 'sold_out');
        $this->assertError($this->buy(['offer' => 'nicht-da', 'confirmed' => true]), 404, 'product_not_found');
    }

    #[Test]
    public function the_portal_link_needs_a_confirmed_address(): void
    {
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);

        $this->assertError($this->api('POST', 'portal'), 403, 'email_unverified');

        Accounts::verification()->markVerified($user);

        $url = $this->api('POST', 'portal')->assertOk()->json('url');
        $this->assertStringContainsString('/!/statamic-payments/konto/link/', $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
