<?php

namespace Goldnead\AppApi;

use Closure;
use Goldnead\AppApi\Support\Areas;
use Goldnead\AppApi\Support\Endpoints;
use Goldnead\AppApi\Support\OpenApi;
use Goldnead\AppApi\Support\UserResource;
use Statamic\Auth\User;

/**
 * The public API, behind the `AppApi` facade.
 */
class AppApiManager
{
    /**
     * Change what the API says about a user: receives the array and the
     * user, returns the array. For fields that are not plain blueprint
     * values (those go in `app-api.user.fields`).
     *
     * @param  (Closure(array<string, mixed>, User): array<string, mixed>)|null  $transformer
     */
    public function transformUserUsing(?Closure $transformer): static
    {
        UserResource::transformUsing($transformer);

        return $this;
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return UserResource::make($user);
    }

    public function active(string $area): bool
    {
        return Areas::active($area);
    }

    /** @return list<array<string, mixed>> */
    public function endpoints(): array
    {
        return Endpoints::active();
    }

    /** @return array<string, mixed> */
    public function openApi(bool $activeOnly = true): array
    {
        return OpenApi::build($activeOnly);
    }
}
