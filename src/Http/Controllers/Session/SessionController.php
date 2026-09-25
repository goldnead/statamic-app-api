<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return new JsonResponse(['user' => UserResource::make(Users::current($request))]);
    }

    /**
     * Statamic's logout plus what a cookie session needs on top: a new
     * session and a new CSRF token, so the old cookie is worth nothing.
     * A request with a token signs nothing out; the token stays valid until
     * it is revoked (DELETE tokens/{id}).
     */
    public function logout(Request $request): Response
    {
        Auth::guard((string) config('statamic.users.guards.web', 'web'))->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
