<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Services\Tokens;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function __construct(protected Tokens $tokens) {}

    public function index(Request $request): JsonResponse
    {
        $user = Users::current($request);

        return new JsonResponse([
            'data' => $this->tokens->of($user)->map(fn (PersonalAccessToken $token) => Tokens::present($token))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'abilities' => ['nullable', 'array', 'max:50'],
            'abilities.*' => ['string', 'max:191'],
        ]);

        $created = $this->tokens->create(Users::current($request), $data['name'], $data['abilities'] ?? null);

        return new JsonResponse([
            'token' => Tokens::present($created['token']),
            // Shown once. Only its hash is stored.
            'plain_text_token' => $created['plain'],
        ], 201);
    }

    public function destroy(Request $request, string $token): Response
    {
        $this->tokens->revoke(Users::current($request), $token);

        return response()->noContent();
    }
}
