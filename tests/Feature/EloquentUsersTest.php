<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Events\TokenCreated;
use Goldnead\AppApi\Events\TokenRevoked;
use Goldnead\AppApi\Tests\Fixtures\EloquentUser;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\Teams\Facades\Teams;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * ChoirLive's setup: `users.repository = eloquent`, Laravel's eloquent user
 * provider, integer ids, Sanctum's `HasApiTokens` on the model, and tokens
 * switched on.
 */
class EloquentUsersTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.users.repository', 'eloquent');
        $app['config']->set('statamic.users.repositories.eloquent.model', EloquentUser::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => EloquentUser::class]);
        $app['config']->set('app-api.areas.tokens', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password')->nullable();
            $t->boolean('super')->default(false);
            $t->json('preferences')->nullable();
            $t->timestamp('last_login')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('role_id');
        });

        Schema::create('group_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('group_id');
        });
    }

    protected function eloquentUser(string $email = 'sina@example.com'): EloquentUser
    {
        return EloquentUser::create(['name' => 'Sina', 'email' => $email, 'password' => bcrypt('geheim-123')]);
    }

    #[Test]
    public function login_and_me_work_with_integer_ids(): void
    {
        $model = $this->eloquentUser();

        $this->api('POST', 'session/login', ['email' => 'sina@example.com', 'password' => 'geheim-123'])
            ->assertOk()
            ->assertJsonPath('user.id', $model->id);

        $this->api('GET', 'me')->assertOk()->assertJsonPath('user.id', $model->id)->assertJsonPath('user.email_verified', false);
    }

    #[Test]
    public function teams_work_with_integer_ids(): void
    {
        $owner = $this->eloquentUser('owner@example.com');
        $member = $this->eloquentUser('member@example.com');
        $team = Teams::create('Kammerchor', User::find($owner->id));
        Teams::addMember($team, User::find($member->id));

        $this->actingAs($owner);

        $this->api('GET', 'teams/'.$team->id.'/members')->assertOk()->assertJsonCount(2, 'data');
        $this->api('DELETE', 'teams/'.$team->id.'/members/'.$member->id)->assertNoContent();
    }

    #[Test]
    public function a_token_is_created_used_and_revoked(): void
    {
        Event::fake([TokenCreated::class, TokenRevoked::class]);
        $model = $this->eloquentUser();
        $this->actingAs($model);

        $created = $this->api('POST', 'tokens', ['name' => 'Notenpult'])
            ->assertCreated()
            ->assertJsonPath('token.name', 'Notenpult');

        $plain = $created->json('plain_text_token');
        $id = $created->json('token.id');
        Event::assertDispatched(TokenCreated::class, fn (TokenCreated $e) => $e->tokenId === $id && ! str_contains(json_encode($e->payload()), $plain));

        // With the token and without a session: a plain API client.
        $this->signOut();
        $this->getJson('/api/app/me', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('user.id', $model->id);

        $this->getJson('/api/app/tokens', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.token');

        $this->deleteJson('/api/app/tokens/'.$id, [], ['Authorization' => 'Bearer '.$plain])->assertNoContent();
        Event::assertDispatched(TokenRevoked::class, fn (TokenRevoked $e) => $e->tokenId === $id && $e->by === 'user');

        $this->assertNull(PersonalAccessToken::find($id));
    }

    #[Test]
    public function a_foreign_token_cannot_be_revoked(): void
    {
        $other = $this->eloquentUser('fremd@example.com');
        $token = $other->createToken('fremd')->accessToken;

        $this->actingAs($this->eloquentUser());

        $this->assertError($this->api('DELETE', 'tokens/'.$token->id), 404, 'not_found');
        $this->assertNotNull(PersonalAccessToken::find($token->id));
    }

    #[Test]
    public function the_token_endpoints_need_a_session_or_token(): void
    {
        $this->assertError($this->api('GET', 'tokens'), 401, 'unauthenticated');
        $this->assertError($this->api('POST', 'tokens', ['name' => 'x']), 401, 'unauthenticated');
        $this->assertError($this->getJson('/api/app/tokens', ['Authorization' => 'Bearer 1|falsch']), 401, 'unauthenticated');
    }

    #[Test]
    public function the_cp_lists_every_token_and_revokes_one_with_the_permission(): void
    {
        Event::fake([TokenRevoked::class]);
        $owner = $this->eloquentUser('sina@example.com');
        $token = $owner->createToken('Notenpult')->accessToken;

        $admin = $this->eloquentUser('admin@example.com');
        Gate::before(fn ($user, $ability) => in_array($ability, ['access cp', 'view app api', 'manage app api tokens'], true) ? true : null);
        $this->actingAs($admin);

        $this->get(cp_route('app-api.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('tokens.enabled', true)
            ->where('tokens.rows.0.name', 'Notenpult')
            ->where('tokens.rows.0.user.email', 'sina@example.com')
            ->etc());

        $this->delete(cp_route('app-api.tokens.destroy', $token->id))->assertRedirect();

        $this->assertNull(PersonalAccessToken::find($token->id));
        Event::assertDispatched(TokenRevoked::class, fn (TokenRevoked $e) => $e->by === 'cp' && $e->actorId === (string) $admin->id);
    }

    #[Test]
    public function abilities_outside_the_configured_list_are_refused(): void
    {
        config(['app-api.tokens.abilities' => ['scores:read']]);
        $this->actingAs($this->eloquentUser());

        $this->assertError($this->api('POST', 'tokens', ['name' => 'x', 'abilities' => ['admin']]), 422, 'validation_failed')
            ->assertJsonPath('error.field', 'abilities');

        $this->api('POST', 'tokens', ['name' => 'x'])->assertCreated()->assertJsonPath('token.abilities', ['scores:read']);
    }
}
