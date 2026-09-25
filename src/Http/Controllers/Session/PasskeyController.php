<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Exception;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\UserResource;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\User\PasskeyLoginController;

/**
 * Passkey login through Statamic's WebAuthn: `options()` is core's as it
 * is, `login()` validates the assertion in core and answers with the user
 * instead of a redirect URL.
 */
class PasskeyController extends PasskeyLoginController
{
    public function login(Request $request)
    {
        try {
            parent::login($request);
        } catch (Exception $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }

            // Core's WebAuthn throws a plain exception for an unknown
            // credential, a user it cannot find and a failed signature.
            throw ApiException::make('invalid_passkey', 422, 'id');
        }

        return new JsonResponse(['user' => UserResource::make(Users::current($request))]);
    }
}
