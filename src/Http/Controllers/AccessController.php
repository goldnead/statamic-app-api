<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Support\Subjects;
use Goldnead\AppApi\Support\Users;
use Goldnead\Entitlements\Facades\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * What statamic-entitlements says about the user and the current team.
 *
 * `user` counts everything the person holds, personally and through each
 * of their teams (statamic-teams expands the subject). `team` is the team
 * of this request alone, null without one. `allowed` in the product answer
 * is either of the two.
 */
class AccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Users::current($request);
        $team = Subjects::team();

        return new JsonResponse([
            'user' => $this->subject($user),
            'team' => $team === null ? null : ['id' => (int) $team->getKey()] + $this->subject($team),
        ]);
    }

    public function product(Request $request, string $product): JsonResponse
    {
        $user = Users::current($request);
        $team = Subjects::team();

        $personal = Entitlements::decide($user, $product)->toArray();
        $shared = $team === null ? null : Entitlements::decide($team, $product)->toArray();

        return new JsonResponse([
            'product' => $product,
            'allowed' => $personal['allowed'] || ($shared['allowed'] ?? false),
            'user' => $personal,
            'team' => $shared,
        ]);
    }

    public function quota(Request $request, string $key): JsonResponse
    {
        $request->validate(['current' => ['nullable', 'integer', 'min:0']]);

        $user = Users::current($request);
        $team = Subjects::team();
        $current = $request->filled('current') ? (int) $request->query('current') : null;

        return new JsonResponse([
            'key' => $key,
            'user' => Entitlements::quota($user, $key, $current)->toArray(),
            'team' => $team === null ? null : Entitlements::quota($team, $key, $current)->toArray(),
        ]);
    }

    /** @return array{products: list<string>, quotas: array<string, mixed>|object} */
    protected function subject(mixed $subject): array
    {
        $quotas = array_map(fn ($quota) => $quota->toArray(), Entitlements::quotasFor($subject));

        return [
            'products' => Entitlements::activeProductSlugsFor($subject),
            // An object even when empty, so a client can index it by key.
            'quotas' => $quotas === [] ? (object) [] : $quotas,
        ];
    }
}
