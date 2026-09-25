<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\Accounts\Facades\Accounts;
use Goldnead\Accounts\Models\AccountRequest;
use Goldnead\Accounts\Services\Impersonation;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * statamic-accounts through the API. Elevated sessions are off in this
 * class (as in the account addon's own suite); ElevationTest covers the 423.
 */
class AccountTest extends TestCase
{
    #[Test]
    public function every_account_endpoint_needs_a_session(): void
    {
        foreach ([
            ['GET', 'account'], ['POST', 'account/verification'], ['POST', 'account/email'], ['DELETE', 'account/email'],
            ['POST', 'account/deletion'], ['DELETE', 'account/deletion'], ['POST', 'account/export'],
        ] as [$method, $uri]) {
            $this->assertError($this->api($method, $uri), 401, 'unauthenticated');
        }
    }

    #[Test]
    public function the_account_state_lists_verification_change_and_deletion(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->api('GET', 'account')
            ->assertOk()
            ->assertJsonPath('account.email', 'sina@example.com')
            ->assertJsonPath('account.verification.verified', false)
            ->assertJsonPath('account.email_change', null)
            ->assertJsonPath('account.deletion', null)
            ->assertJsonPath('account.deletion_blockers', []);
    }

    #[Test]
    public function the_confirmation_mail_is_sent_again_and_refused_once_confirmed(): void
    {
        Mail::fake();
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);

        $this->api('POST', 'account/verification')->assertStatus(202)->assertJsonPath('sent', true);

        Accounts::verification()->markVerified($user);

        $this->assertError($this->api('POST', 'account/verification'), 409, 'already_verified');
    }

    #[Test]
    public function a_change_of_address_is_requested_and_withdrawn(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->api('POST', 'account/email', ['email' => 'neu@example.com'])
            ->assertStatus(202)
            ->assertJsonPath('account.email_change.email', 'neu@example.com');

        $this->api('DELETE', 'account/email')->assertOk()->assertJsonPath('account.email_change', null);
    }

    #[Test]
    public function an_address_that_is_taken_is_422_email_rejected(): void
    {
        $this->makeUser('vergeben@example.com');
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->api('POST', 'account/email', ['email' => 'vergeben@example.com']), 422, 'email_rejected')
            ->assertJsonPath('error.field', 'email');
    }

    #[Test]
    public function the_deletion_is_scheduled_and_withdrawn(): void
    {
        Mail::fake();
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);

        $this->api('POST', 'account/deletion')
            ->assertStatus(202)
            ->assertJsonPath('account.deletion.status', AccountRequest::STATUS_PENDING);

        $this->assertNotNull(Accounts::deletion()->pending($user));

        $this->api('DELETE', 'account/deletion')->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('account.deletion', null);
    }

    #[Test]
    public function a_blocked_deletion_is_409_with_the_blockers(): void
    {
        Mail::fake();
        $user = $this->makeUser('sina@example.com');
        $team = Teams::create('Kammerchor', $user);
        Teams::addMember($team, $this->makeUser('bob@example.com'));
        $this->actingAs($user);

        $blockers = Accounts::deletion()->blockers($user);
        $this->assertNotEmpty($blockers, 'statamic-teams blocks deleting the owner of a team with members');

        $this->assertError($this->api('POST', 'account/deletion'), 409, 'deletion_blocked')
            ->assertJsonPath('error.details.blockers', $blockers);
    }

    #[Test]
    public function the_export_is_built_and_downloaded_once_by_its_owner_only(): void
    {
        $user = $this->makeUser('sina@example.com');
        $this->actingAs($user);

        $url = $this->api('POST', 'account/export')->assertCreated()->json('download_url');
        $this->assertStringContainsString('/api/app/account/export/', $url);

        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        // Somebody else with the link gets nothing.
        $this->actingAs($this->makeUser('fremd@example.com'));
        $this->assertError($this->get($path, ['Accept' => 'application/json']), 404, 'not_found');

        $this->actingAs($user);
        $this->get($path)->assertOk()->assertDownload();

        $this->assertError($this->get($path, ['Accept' => 'application/json']), 404, 'not_found');
    }

    #[Test]
    public function a_tampered_download_link_is_403(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        $this->assertError($this->get('/api/app/account/export/abc?expires=9999999999&signature=falsch', ['Accept' => 'application/json']), 403, 'invalid_signature');
    }

    #[Test]
    public function while_an_admin_is_signed_in_as_the_customer_the_account_actions_are_refused(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser('sina@example.com'));
        $this->withSession([Impersonation::SESSION_KEY => 'admin-1']);

        $this->assertError($this->api('POST', 'account/email', ['email' => 'neu@example.com']), 403, 'impersonation_locked');
        $this->assertError($this->api('POST', 'account/deletion'), 403, 'impersonation_locked');
        $this->assertError($this->api('POST', 'account/export'), 403, 'impersonation_locked');
    }
}
