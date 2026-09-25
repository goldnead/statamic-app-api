<?php

namespace Goldnead\AppApi\Http\Controllers\Cp;

use Goldnead\AppApi\Services\Tokens;
use Goldnead\AppApi\Support\OpenApi;
use Goldnead\AppApi\Support\Overview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Statamic\Http\Controllers\CP\CpController;

class OverviewController extends CpController
{
    public function index(Request $request, Overview $overview)
    {
        $this->authorize('view app api');

        return Inertia::render('app-api::Overview', $overview->toArray() + [
            'canManageTokens' => (bool) $request->user()?->can('manage app api tokens'),
            'openapiCpUrl' => cp_route('app-api.openapi'),
        ]);
    }

    /**
     * The full description (every area, active or not), for reading in the
     * browser or saving; the public one under the API prefix only lists what
     * this site has on.
     */
    public function openapi(): JsonResponse
    {
        $this->authorize('view app api');

        return new JsonResponse(OpenApi::build(false, url('/')), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function revokeToken(Request $request, string $token, Tokens $tokens): RedirectResponse
    {
        $this->authorize('manage app api tokens');

        $class = Sanctum::$personalAccessTokenModel;
        $record = $class::query()->find($token);

        abort_unless($record instanceof PersonalAccessToken, 404);

        $tokens->delete($record, 'cp', (string) $request->user()?->getAuthIdentifier());

        return back()->with('success', __('app-api::cp.token_revoked'));
    }
}
