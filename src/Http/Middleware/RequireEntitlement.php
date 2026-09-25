<?php

namespace Goldnead\AppApi\Http\Middleware;

use Closure;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Support\Subjects;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `app-api.entitled:<product>[,<product>…]` for a site's own routes: the
 * request passes when the user, personally or through one of their teams,
 * or the current team may use one of the products. Otherwise 402
 * `payment_required`, with the products in `details`, so the app can show
 * the way to buy instead of an error.
 *
 * Asks `Entitlements::allows()`; decides nothing itself. Put it after the
 * auth middleware (and `app-api.team` when the current team should count).
 */
class RequireEntitlement
{
    public function handle(Request $request, Closure $next, string ...$products): Response
    {
        $user = Users::current($request);

        foreach ($products as $product) {
            if (Subjects::allows($user, $product)) {
                return $next($request);
            }
        }

        throw ApiException::make('payment_required', 402, null, ['products' => array_values($products)]);
    }
}
