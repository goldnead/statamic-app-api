<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * The critic's probes from the review of 8fe55e3, as tests. Each was red
 * against that commit.
 */
class KritikTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products.lifetime.digital', true);
    }

    /** @return array<string, mixed> */
    protected function terms(string $query = 'product=lifetime'): array
    {
        return $this->api('GET', 'checkout/terms?'.$query)->assertOk()->json();
    }

    #[Test]
    public function enforced_two_factor_blocks_the_api_until_it_is_set_up(): void
    {
        config(['statamic.users.two_factor_enforced_roles' => ['*']]);
        $this->makeUser('sina@example.com');

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])
            ->assertOk()
            ->assertJsonPath('two_factor_setup_required', true);

        // Session, logout and the setup itself stay open.
        $this->api('GET', 'me')->assertOk();

        $this->assertError($this->api('GET', 'account'), 403, 'two_factor_setup_required');
        $this->assertError($this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true]), 403, 'two_factor_setup_required');
        $this->assertSame(0, Payment::count());

        $setup = $this->api('POST', 'session/two-factor/setup')->assertOk()->json();
        $this->assertNotEmpty($setup['secret_key']);
        $this->assertStringContainsString('/api/app/session/two-factor/confirm', $setup['confirm_url']);

        $this->assertError($this->api('POST', 'session/two-factor/confirm', ['code' => '000000']), 422, 'validation_failed');

        $this->api('POST', 'session/logout')->assertNoContent();
    }

    #[Test]
    public function a_payment_is_not_found_by_an_unconfirmed_address(): void
    {
        $victim = Payment::query()->forceCreate([
            'provider' => 'mollie', 'provider_id' => 'tr_victim', 'product' => 'lifetime',
            'amount_cent' => 7900, 'status' => 'paid', 'email' => 'opfer@example.com', 'meta' => [],
        ]);

        $this->api('POST', 'session/register', ['email' => 'opfer@example.com', 'password' => 'Geheim-12345!', 'password_confirmation' => 'Geheim-12345!'])->assertCreated();

        $this->assertError($this->api('GET', 'checkout/'.$victim->getKey()), 404, 'not_found');
        $this->assertError($this->api('POST', 'portal'), 403, 'email_unverified');
    }

    #[Test]
    public function an_idempotency_key_with_another_body_is_422(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true, 'consent_version' => $this->terms()['consent_version']], ['Idempotency-Key' => 'k1'])->assertCreated();

        $this->assertError($this->api('POST', 'checkout', ['product' => 'chortarif', 'confirmed' => true, 'consent_version' => $this->terms('product=chortarif')['consent_version']], ['Idempotency-Key' => 'k1']), 422, 'idempotency_key_reused');
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_request_from_outside_the_stateful_domains_is_a_clear_400_not_a_500(): void
    {
        $this->makeUser('sina@example.com');

        $this->assertError($this->json('POST', '/api/app/session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123']), 400, 'stateful_origin_required')
            ->assertJsonPath('error.details.config', 'sanctum.stateful');

        $this->assertError($this->json('POST', '/api/app/session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'], ['Origin' => 'https://evil.example']), 400, 'stateful_origin_required');
    }

    #[Test]
    public function an_unknown_invitation_is_404(): void
    {
        $this->assertError($this->api('GET', 'teams/invitations/abc'), 404, 'invitation_not_found');
    }

    #[Test]
    public function the_terms_name_the_wording_and_the_checkout_insists_on_its_version(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $digital = $this->terms();
        $this->assertTrue($digital['digital']);
        $this->assertSame(__('statamic-payments::messages.order_consent'), $digital['consent_text']);
        $this->assertStringContainsString('withdrawal', $digital['consent_text']);
        $this->assertSame('zahlungspflichtig bestellen', $digital['button_label']);

        // Not digital: no waiver, none stored.
        $physical = $this->terms('product=chortarif');
        $this->assertFalse($physical['digital']);
        $this->assertNull($physical['consent_text']);

        $this->assertError($this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true]), 422, 'validation_failed')
            ->assertJsonPath('error.field', 'consent_version');

        $this->assertError($this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true, 'consent_version' => 'alt']), 409, 'consent_changed')
            ->assertJsonPath('error.details.consent_version', $digital['consent_version']);

        // `confirmed` must be accepted, not merely present.
        $this->assertError($this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => 'nein', 'consent_version' => $digital['consent_version']]), 422, 'consent_required');

        $id = $this->api('POST', 'checkout', ['product' => 'lifetime', 'confirmed' => true, 'consent_version' => $digital['consent_version']])->assertCreated()->json('payment.id');
        $this->assertSame($digital['consent_text'], Payment::find($id)->consent_text);

        $other = $this->api('POST', 'checkout', ['product' => 'chortarif', 'confirmed' => true, 'consent_version' => $physical['consent_version']])->assertCreated()->json('payment.id');
        $this->assertNull(Payment::find($other)->consent_text);
    }

    #[Test]
    public function an_offer_carries_its_own_waiver_and_version(): void
    {
        Offer::create(['handle' => 'lifetime-50', 'name' => 'Lifetime', 'product' => 'lifetime', 'amount_cent' => 7900, 'quantity_limit' => 50]);
        $this->actingAs($this->makeUser('sina@example.com'));

        $terms = $this->terms('offer=lifetime-50');
        $this->assertTrue($terms['digital']);
        $this->assertSame(Offer::where('handle', 'lifetime-50')->first()->withdrawalTerms()['version'], $terms['consent_version']);

        $this->api('POST', 'checkout', ['offer' => 'lifetime-50', 'confirmed' => true, 'consent_version' => $terms['consent_version']])->assertCreated();
    }

    #[Test]
    public function the_openapi_description_names_the_order_button(): void
    {
        $this->assertStringContainsString('zahlungspflichtig bestellen', (string) $this->api('GET', 'openapi.json')->json('paths./api/app/checkout.post.description'));
    }
}
