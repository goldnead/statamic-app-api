<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Statamic\Auth\User;

/**
 * statamic-teams as JSON.
 *
 * Every change goes through the `Teams` facade with the signed-in user as
 * actor, so the user's role in that team decides (a refusal keeps its
 * reason as the error code). A team the user is not in answers 403
 * `not_member` whether it exists or not.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Users::current($request);
        $current = Teams::current($user);

        return new JsonResponse([
            'data' => Teams::teamsOf($user)->map(fn (Team $team) => $this->team($team, $user, $current))->values()->all(),
            'current_team_id' => $current === null ? null : (int) $current->getKey(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        $user = Users::current($request);

        $team = Teams::create($data['name'], $user);
        Teams::switch($user, $team);

        return new JsonResponse(['team' => $this->team($team, $user, $team)], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $user = Users::current($request);
        $team = Teams::currentOrFail();

        return new JsonResponse(['team' => $this->team($team, $user, $team)]);
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate(['team' => ['required']]);
        $user = Users::current($request);
        $team = $this->memberTeam((string) $data['team'], $user);

        Teams::switch($user, $team);

        return new JsonResponse(['team' => $this->team($team, $user, $team)]);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = Users::current($request);

        $team = Teams::joinByCode($data['code'], $user)->team ?? throw ApiException::notFound();
        Teams::switch($user, $team);

        return new JsonResponse(['team' => $this->team($team, $user, $team)]);
    }

    public function invitation(string $token): JsonResponse
    {
        $invitation = Teams::invitation($token);
        $team = $invitation->team;

        return new JsonResponse(['invitation' => [
            'team' => $team === null ? null : ['id' => (int) $team->getKey(), 'uuid' => (string) $team->uuid, 'name' => (string) $team->name],
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $user = Users::current($request);
        $team = Teams::acceptInvitation($token, $user)->team ?? throw ApiException::notFound();

        Teams::switch($user, $team);

        return new JsonResponse(['team' => $this->team($team, $user, $team)]);
    }

    public function show(Request $request, string $team): JsonResponse
    {
        $user = Users::current($request);
        $record = $this->memberTeam($team, $user);

        return new JsonResponse(['team' => $this->team($record, $user, Teams::current($user))]);
    }

    public function members(Request $request, string $team): JsonResponse
    {
        $record = $this->memberTeam($team, Users::current($request));

        return new JsonResponse([
            'data' => Teams::members($record)->map(fn (Membership $member) => $this->member($member, $record))->values()->all(),
        ]);
    }

    public function changeRole(Request $request, string $team, string $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'max:64']]);
        $actor = Users::current($request);
        $record = $this->memberTeam($team, $actor);

        $membership = Teams::changeRole($record, $this->memberKey($record, $user), $data['role'], $actor);

        return new JsonResponse(['member' => $this->member($membership, $record)]);
    }

    public function removeMember(Request $request, string $team, string $user): Response
    {
        $actor = Users::current($request);
        $record = $this->memberTeam($team, $actor);

        Teams::removeMember($record, $this->memberKey($record, $user), $actor);

        return response()->noContent();
    }

    public function leave(Request $request, string $team): Response
    {
        $user = Users::current($request);

        Teams::leave($this->memberTeam($team, $user), $user);

        return response()->noContent();
    }

    public function invitations(Request $request, string $team): JsonResponse
    {
        $user = Users::current($request);
        $record = $this->memberTeam($team, $user);

        if (! Teams::can($user, $record, 'invite members')) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        return new JsonResponse([
            'data' => Teams::pendingInvitationsOf($record)->map(fn (Invitation $invitation) => $invitation->summary())->values()->all(),
        ]);
    }

    public function invite(Request $request, string $team): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'role' => ['nullable', 'string', 'max:64'],
        ]);

        $user = Users::current($request);
        $issued = Teams::invite($this->memberTeam($team, $user), $data['email'], $data['role'] ?? null, [], $user);

        return new JsonResponse([
            // The link exists only now (the database keeps its hash): the
            // inviter may copy it instead of relying on the mail.
            'invitation' => $issued->invitation->summary() + ['url' => $issued->url],
        ], 201);
    }

    public function revokeInvitation(Request $request, string $team, string $invitation): Response
    {
        $user = Users::current($request);
        $record = $this->memberTeam($team, $user);

        $found = Invitation::query()->where('team_id', $record->getKey())->find($invitation)
            ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);

        Teams::revokeInvitation($found, $user);

        return response()->noContent();
    }

    public function regenerateJoinCode(Request $request, string $team): JsonResponse
    {
        $user = Users::current($request);

        return new JsonResponse(['join_code' => Teams::regenerateJoinCode($this->memberTeam($team, $user), $user)]);
    }

    /**
     * The team, if the user is in it. Unknown and foreign teams are the same
     * 403, so the answer does not reveal which ids exist.
     */
    protected function memberTeam(string $id, User $user): Team
    {
        $team = Teams::find($id);

        if ($team === null || ! $team->hasMember($user)) {
            throw ApiException::make('not_member', 403, 'team');
        }

        return $team;
    }

    /**
     * A member of this team by user id. Someone who is not in it is a 404
     * (`not_found`), not a question for the role check.
     */
    protected function memberKey(Team $team, string $user): string
    {
        if ($team->membershipOf($user) === null) {
            throw ApiException::make('not_found', 404, 'user');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    protected function team(Team $team, User $user, ?Team $current): array
    {
        $role = Teams::roleOf($user, $team);
        $permissions = array_values(array_filter(
            (array) config('teams.permissions', []),
            fn ($permission) => is_string($permission) && Teams::can($user, $team, $permission),
        ));

        return $team->summary() + [
            'role' => $role,
            'permissions' => $permissions,
            'is_current' => $current !== null && (int) $current->getKey() === (int) $team->getKey(),
            'is_personal' => $team->isPersonal(),
            'read_only' => $team->isReadOnly(),
            'join_method' => $team->join_method,
            // Only for those who may invite: a join code is a key.
            'join_code' => in_array('invite members', $permissions, true) ? $team->join_code : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function member(Membership $membership, Team $team): array
    {
        $user = $membership->user();

        return [
            'user' => [
                'id' => $user?->id() ?? $membership->user_id,
                'email' => $user?->email(),
                'name' => $user?->name(),
                'avatar' => $user?->avatar(),
            ],
            'role' => $membership->role,
            'is_owner' => $membership->role === config('teams.owner_role', 'owner'),
            'meta' => $membership->meta ?? (object) [],
            'joined_at' => $membership->joined_at?->toIso8601String(),
        ];
    }
}
