<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Tests\TestCase;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;

/**
 * statamic-teams through the API: every change with the signed-in user as
 * actor, a foreign or unknown team is 403 `not_member`.
 */
class TeamsTest extends TestCase
{
    protected User $owner;

    protected User $member;

    protected User $stranger;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = $this->makeUser('owner@example.com');
        $this->member = $this->makeUser('member@example.com');
        $this->stranger = $this->makeUser('fremd@example.com');

        $this->team = Teams::create('Kammerchor', $this->owner);
        Teams::addMember($this->team, $this->member);
    }

    #[Test]
    public function every_team_endpoint_needs_a_session(): void
    {
        foreach ([
            ['GET', 'teams'], ['POST', 'teams'], ['GET', 'teams/current'], ['PUT', 'teams/current'],
            ['POST', 'teams/join'], ['GET', 'teams/'.$this->team->id], ['GET', 'teams/'.$this->team->id.'/members'],
            ['POST', 'teams/'.$this->team->id.'/invitations'], ['POST', 'teams/'.$this->team->id.'/leave'],
        ] as [$method, $uri]) {
            $this->assertError($this->api($method, $uri), 401, 'unauthenticated');
        }
    }

    #[Test]
    public function the_list_shows_the_teams_of_the_user_with_role_and_current(): void
    {
        $this->actingAs($this->owner);

        $this->api('GET', 'teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kammerchor')
            ->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('current_team_id', $this->team->id);
    }

    #[Test]
    public function a_new_team_belongs_to_its_creator_and_becomes_current(): void
    {
        $this->actingAs($this->stranger);

        $id = $this->api('POST', 'teams', ['name' => 'Neuer Chor'])
            ->assertCreated()
            ->assertJsonPath('team.role', 'owner')
            ->assertJsonPath('team.is_current', true)
            ->json('team.id');

        $this->api('GET', 'me')->assertJsonPath('user.current_team_id', $id);
    }

    #[Test]
    public function a_foreign_or_unknown_team_is_403_not_member(): void
    {
        $other = Teams::create('Anderer Chor', $this->stranger);
        $this->actingAs($this->member);

        $this->assertError($this->api('GET', 'teams/'.$other->id), 403, 'not_member');
        $this->assertError($this->api('GET', 'teams/'.$other->uuid.'/members'), 403, 'not_member');
        $this->assertError($this->api('GET', 'teams/999999'), 403, 'not_member');
        $this->assertError($this->api('PUT', 'teams/current', ['team' => $other->id]), 403, 'not_member');
        $this->assertError($this->api('POST', 'teams/'.$other->id.'/invitations', ['email' => 'x@example.com']), 403, 'not_member');
        $this->assertError($this->api('POST', 'teams/'.$other->id.'/leave'), 403, 'not_member');
    }

    #[Test]
    public function the_team_header_names_the_current_team_and_a_foreign_one_is_403(): void
    {
        $other = Teams::create('Anderer Chor', $this->stranger);
        $second = Teams::create('Zweiter Chor', $this->member);
        $this->actingAs($this->member);

        $this->api('GET', 'teams/current', [], ['X-Team-ID' => (string) $second->id])->assertOk()->assertJsonPath('team.id', $second->id);
        $this->api('GET', 'teams/current', [], ['X-Team-ID' => $this->team->uuid])->assertOk()->assertJsonPath('team.id', $this->team->id);

        $this->assertError($this->api('GET', 'teams/current', [], ['X-Team-ID' => (string) $other->id]), 403, 'not_member');
        $this->assertError($this->api('GET', 'teams/current', [], ['X-Team-ID' => '424242']), 403, 'not_member');
    }

    #[Test]
    public function the_header_name_is_configurable_as_choirlive_needs_it(): void
    {
        config(['app-api.teams.header' => 'X-Tenant-ID', 'teams.current.fallback_to_current' => false]);
        $this->actingAs($this->member);

        $this->api('GET', 'teams/current', [], ['X-Tenant-ID' => (string) $this->team->id])->assertOk()->assertJsonPath('team.id', $this->team->id);

        // Without the header and without the fallback: no team.
        $this->assertError($this->api('GET', 'teams/current'), 422, 'team_required');
    }

    #[Test]
    public function switching_makes_a_team_current(): void
    {
        $second = Teams::create('Zweiter Chor', $this->member);
        $this->actingAs($this->member);

        $this->api('PUT', 'teams/current', ['team' => $this->team->id])->assertOk()->assertJsonPath('team.is_current', true);
        $this->api('GET', 'teams')->assertJsonPath('current_team_id', $this->team->id);

        $this->api('PUT', 'teams/current', ['team' => $second->uuid])->assertOk();
        $this->api('GET', 'teams')->assertJsonPath('current_team_id', $second->id);
    }

    #[Test]
    public function members_are_listed_for_members_only(): void
    {
        $this->actingAs($this->member);

        $this->api('GET', 'teams/'.$this->team->id.'/members')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user.email', 'owner@example.com')
            ->assertJsonPath('data.0.is_owner', true);
    }

    #[Test]
    public function the_owner_invites_and_the_invited_person_accepts(): void
    {
        $this->actingAs($this->owner);

        $invitation = $this->api('POST', 'teams/'.$this->team->id.'/invitations', ['email' => 'fremd@example.com'])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'fremd@example.com')
            ->json('invitation');

        $token = basename(parse_url($invitation['url'], PHP_URL_PATH));

        $this->api('GET', 'teams/'.$this->team->id.'/invitations')->assertOk()->assertJsonCount(1, 'data');

        // The preview works without a session: the token is the key.
        $this->signOut();
        $this->api('GET', 'teams/invitations/'.$token)->assertOk()->assertJsonPath('invitation.team.name', 'Kammerchor');

        $this->actingAs($this->stranger);
        $this->api('POST', 'teams/invitations/'.$token.'/accept')->assertOk()->assertJsonPath('team.id', $this->team->id)->assertJsonPath('team.is_current', true);

        $this->assertTrue($this->team->fresh()->hasMember($this->stranger));
    }

    #[Test]
    public function an_invitation_for_another_address_is_refused(): void
    {
        $issued = Teams::invite($this->team, 'jemand@example.com');
        $this->actingAs($this->stranger);

        $this->assertError($this->api('POST', 'teams/invitations/'.$issued->token.'/accept'), 403, 'invitation_wrong_email');
        $this->assertError($this->api('POST', 'teams/invitations/unbekannt123/accept'), 404, 'invitation_not_found');
    }

    #[Test]
    public function a_member_may_not_invite_or_list_invitations(): void
    {
        $this->actingAs($this->member);

        $this->assertError($this->api('POST', 'teams/'.$this->team->id.'/invitations', ['email' => 'x@example.com']), 403, 'forbidden');
        $this->assertError($this->api('GET', 'teams/'.$this->team->id.'/invitations'), 403, 'forbidden');
    }

    #[Test]
    public function an_invitation_is_withdrawn(): void
    {
        $issued = Teams::invite($this->team, 'jemand@example.com');
        $this->actingAs($this->owner);

        $this->api('DELETE', 'teams/'.$this->team->id.'/invitations/'.$issued->invitation->id)->assertNoContent();
        $this->assertError($this->api('DELETE', 'teams/'.$this->team->id.'/invitations/999'), 404, 'invitation_not_found');
    }

    #[Test]
    public function joining_by_code_works_and_a_wrong_code_is_404(): void
    {
        $this->actingAs($this->owner);
        Teams::update($this->team, ['join_method' => Team::JOIN_CODE]);

        $code = $this->api('POST', 'teams/'.$this->team->id.'/join-code')->assertOk()->json('join_code');
        $this->assertIsString($code);

        $this->actingAs($this->stranger);
        $this->assertError($this->api('POST', 'teams/join', ['code' => 'FALSCH']), 404, 'join_code_invalid');
        $this->api('POST', 'teams/join', ['code' => $code])->assertOk()->assertJsonPath('team.id', $this->team->id);
    }

    #[Test]
    public function the_join_code_is_only_shown_to_those_who_may_invite(): void
    {
        Teams::update($this->team, ['join_method' => Team::JOIN_CODE]);
        Teams::regenerateJoinCode($this->team);

        $this->actingAs($this->owner);
        $this->assertNotNull($this->api('GET', 'teams/'.$this->team->id)->json('team.join_code'));

        $this->actingAs($this->member);
        $this->assertNull($this->api('GET', 'teams/'.$this->team->id)->json('team.join_code'));
        $this->assertError($this->api('POST', 'teams/'.$this->team->id.'/join-code'), 403, 'forbidden');
    }

    #[Test]
    public function the_owner_changes_a_role_and_a_member_may_not(): void
    {
        $this->actingAs($this->owner);

        $this->api('PUT', 'teams/'.$this->team->id.'/members/'.$this->member->id().'/role', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('member.role', 'admin');

        $this->assertError($this->api('PUT', 'teams/'.$this->team->id.'/members/'.$this->member->id().'/role', ['role' => 'kaiser']), 422, 'unknown_role');

        $this->actingAs($this->stranger);
        Teams::addMember($this->team, $this->stranger);
        $this->assertError($this->api('PUT', 'teams/'.$this->team->id.'/members/'.$this->owner->id().'/role', ['role' => 'member']), 403, 'forbidden');
    }

    #[Test]
    public function a_member_is_removed_and_a_stranger_id_is_404(): void
    {
        $this->actingAs($this->owner);

        $this->assertError($this->api('DELETE', 'teams/'.$this->team->id.'/members/'.$this->stranger->id()), 404, 'not_found');

        $this->api('DELETE', 'teams/'.$this->team->id.'/members/'.$this->member->id())->assertNoContent();
        $this->assertFalse($this->team->fresh()->hasMember($this->member));
    }

    #[Test]
    public function a_member_leaves_and_the_last_owner_cannot(): void
    {
        $this->actingAs($this->member);
        $this->api('POST', 'teams/'.$this->team->id.'/leave')->assertNoContent();

        $this->actingAs($this->owner);
        $this->assertError($this->api('POST', 'teams/'.$this->team->id.'/leave'), 422, 'last_owner');
    }
}
