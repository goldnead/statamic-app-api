<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\AppApi\Http\Middleware\ResolveTeam;
use Goldnead\AppApi\Support\Areas;
use Goldnead\AppApi\Support\Endpoints;
use Goldnead\AppApi\Support\OpenApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

class MetaController extends Controller
{
    /**
     * What a client needs before its first call: which areas exist here,
     * where the CSRF cookie comes from and which header names the team.
     */
    public function index(): JsonResponse
    {
        $areas = [];

        foreach (Areas::all() as $area) {
            $areas[$area] = Areas::active($area);
        }

        return new JsonResponse([
            'areas' => $areas,
            'prefix' => Endpoints::path(''),
            'team_header' => ResolveTeam::header(),
            'csrf_cookie_url' => Route::has('sanctum.csrf-cookie') ? route('sanctum.csrf-cookie', [], false) : null,
            'two_factor' => Endpoints::holds('two_factor'),
            'elevated_sessions' => Endpoints::holds('elevation'),
            'registration' => Endpoints::holds('registration'),
            'openapi_url' => Endpoints::holds('openapi') ? Endpoints::path('openapi.json') : null,
        ]);
    }

    public function openapi(): JsonResponse
    {
        return new JsonResponse(OpenApi::build(true, url('/')), 200, [], JSON_UNESCAPED_SLASHES);
    }
}
