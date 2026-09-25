<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Statamic\Http\Controllers\Auth\ElevatedSessionController;
use Statamic\Http\Requests\Auth\ElevatedSessionConfirmationRequest;

/**
 * Statamic's elevated session ("confirm it is you"), for a JSON client.
 *
 * Endpoints that change the address, delete the account, export its data
 * or create a token answer 423 `elevation_required` without one, with the
 * method in `details`. The app asks for the password (or the mailed code,
 * or a passkey), posts it here, and repeats the request. The check itself
 * is core's `confirm()`.
 */
class ElevationController extends ElevatedSessionController
{
    public function status(Request $request): JsonResponse
    {
        return new JsonResponse($this->state($request));
    }

    public function confirmJson(ElevatedSessionConfirmationRequest $request): JsonResponse
    {
        $this->confirm($request);

        return new JsonResponse($this->state($request));
    }

    public function sendCode(Request $request): JsonResponse
    {
        $user = Users::current($request);

        if ($user->getElevatedSessionMethod() !== 'verification_code') {
            throw ApiException::make('code_unavailable', 422, 'method');
        }

        session()->sendElevatedSessionVerificationCode();

        return new JsonResponse(['message' => __('statamic::messages.elevated_session_verification_code_sent')], 202);
    }

    /**
     * @return array{enabled: bool, elevated: bool, expires_at: string|null, method: string, passkey: bool}
     */
    protected function state(Request $request): array
    {
        $user = Users::current($request);
        $enabled = (bool) config('statamic.users.elevated_sessions_enabled');
        $method = $user->getElevatedSessionMethod();

        // Without a session (a token request) there is nothing to elevate.
        $expiry = $request->hasSession() ? $request->getElevatedSessionExpiry() : null;

        return [
            'enabled' => $enabled,
            'elevated' => ! $enabled || ($request->hasSession() && $request->hasElevatedSession()),
            'expires_at' => $expiry ? Carbon::createFromTimestamp($expiry)->toIso8601String() : null,
            'method' => $method,
            'passkey' => $method !== 'verification_code' && $user->passkeys()->isNotEmpty(),
        ];
    }
}
