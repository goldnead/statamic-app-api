<?php

namespace Goldnead\AppApi\Http\Controllers\Session;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\ForgotPasswordController as CoreForgotPasswordController;

/**
 * "Forgot password" through Statamic's broker (`statamic.users.passwords`).
 *
 * The mail links to `app-api.auth.password_reset_url` when set, else to
 * Statamic's own reset page; core checks the URL stays on this site. Core
 * answers the same whether the address exists or not, and so does this.
 * Core's constructor redirects a signed-in visitor away; a JSON client gets
 * the same answer either way, so that is left out.
 */
class ForgotPasswordController extends CoreForgotPasswordController
{
    public function __construct() {}

    public function send(Request $request): JsonResponse
    {
        $url = config('app-api.auth.password_reset_url');

        if (! $request->filled('_reset_url') && is_string($url) && $url !== '') {
            $request->merge(['_reset_url' => $url]);
        }

        $response = $this->sendResetLinkEmail($request);
        $message = $response instanceof JsonResponse ? ($response->getData(true)['message'] ?? null) : null;

        return new JsonResponse(['message' => $message ?? __('passwords.sent')], 202);
    }
}
