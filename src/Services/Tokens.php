<?php

namespace Goldnead\AppApi\Services;

use Goldnead\AppApi\Events\TokenCreated;
use Goldnead\AppApi\Events\TokenRevoked;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Statamic\Auth\User;
use Throwable;

/**
 * Personal access tokens, through Sanctum and nothing else.
 *
 * A token belongs to the user's Eloquent model, which needs Sanctum's
 * `HasApiTokens`. Statamic's file users have no model and cannot hold one:
 * for them every call answers 409 `tokens_unsupported`, and the session
 * stays the way in.
 */
class Tokens
{
    public function supports(User $user): bool
    {
        $model = Users::model($user);

        return $model !== null && method_exists($model, 'createToken') && method_exists($model, 'tokens');
    }

    public function tableExists(): bool
    {
        try {
            return Schema::hasTable($this->table());
        } catch (Throwable) {
            return false;
        }
    }

    /** @return Collection<int, PersonalAccessToken> */
    public function of(User $user): Collection
    {
        return $this->model($user)->tokens()->latest('id')->get();
    }

    /**
     * @param  list<string>|null  $abilities
     * @return array{token: PersonalAccessToken, plain: string}
     */
    public function create(User $user, string $name, ?array $abilities = null): array
    {
        $allowed = array_values(array_filter((array) config('app-api.tokens.abilities', ['*']), 'is_string'));
        $abilities = $abilities === null || $abilities === [] ? ['*'] : array_values(array_unique($abilities));

        if (! in_array('*', $allowed, true)) {
            if (in_array('*', $abilities, true)) {
                $abilities = $allowed;
            }

            $unknown = array_diff($abilities, $allowed);

            if ($unknown !== []) {
                throw ApiException::make('validation_failed', 422, 'abilities', ['unknown' => array_values($unknown)]);
            }
        }

        $days = config('app-api.tokens.expires_after_days');
        $expires = is_numeric($days) && (int) $days > 0 ? now()->addDays((int) $days) : null;

        $new = $this->model($user)->createToken($name, $abilities, $expires);
        /** @var PersonalAccessToken $token */
        $token = $new->accessToken;

        TokenCreated::dispatch((string) $user->id(), $user->email(), (int) $token->getKey(), $name, $abilities, $expires?->toIso8601String());

        return ['token' => $token, 'plain' => $new->plainTextToken];
    }

    public function revoke(User $user, int|string $id, string $by = 'user', ?string $actorId = null): void
    {
        $token = $this->model($user)->tokens()->whereKey($id)->first();

        if (! $token instanceof PersonalAccessToken) {
            throw ApiException::notFound();
        }

        $this->delete($token, $by, $actorId);
    }

    public function delete(PersonalAccessToken $token, string $by, ?string $actorId = null): void
    {
        $owner = Users::of($token->tokenable instanceof \Illuminate\Contracts\Auth\Authenticatable ? $token->tokenable : null);
        $name = (string) $token->name;
        $id = (int) $token->getKey();

        $token->delete();

        TokenRevoked::dispatch(
            $owner ? (string) $owner->id() : (string) $token->tokenable_id,
            $owner?->email(),
            $id,
            $name,
            $by,
            $actorId,
        );
    }

    /**
     * Every token, newest first, for the Control Panel.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function all(int $limit = 200): Collection
    {
        if (! $this->tableExists()) {
            return collect();
        }

        $class = Sanctum::$personalAccessTokenModel;

        return $class::query()->with('tokenable')->latest('id')->limit($limit)->get();
    }

    /** @return array<string, mixed> */
    public static function present(PersonalAccessToken $token): array
    {
        return [
            'id' => (int) $token->getKey(),
            'name' => (string) $token->name,
            'abilities' => (array) $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }

    protected function model(User $user): object
    {
        if (! $this->supports($user)) {
            throw ApiException::make('tokens_unsupported', 409);
        }

        return Users::model($user);
    }

    protected function table(): string
    {
        $class = Sanctum::$personalAccessTokenModel;

        return (new $class)->getTable();
    }
}
