<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Statamic\Http\Controllers\ResetPasswordController as CoreResetPasswordController;

/**
 * Setting a new password with the token from the mail, through Statamic's
 * broker and its password rules (`Password::default()`).
 *
 * Core validates first and answers a failed validation with a redirect even
 * to JSON clients; its rules are run here first, so that failure is a 422.
 * Core does not sign the user in afterwards, and neither does this.
 */
class ResetPasswordController extends CoreResetPasswordController
{
    public function __construct() {}

    public function resetWithToken(Request $request): JsonResponse
    {
        $request->validate($this->rules(), $this->validationErrorMessages());

        try {
            $response = $this->reset($request);
        } catch (ValidationException $e) {
            // The broker refused: unknown address, wrong or expired token.
            throw ApiException::make('reset_failed', 422, 'token', [], (string) collect($e->errors())->flatten()->first());
        }

        $message = $response instanceof JsonResponse ? ($response->getData(true)['message'] ?? null) : null;

        return new JsonResponse(['message' => $message ?? __('passwords.reset')]);
    }
}
