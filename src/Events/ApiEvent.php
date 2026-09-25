<?php

namespace Goldnead\AppApi\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An event of this addon's own.
 *
 * The API translates to the siblings and leaves their events to them (a
 * member joining is statamic-teams' event, a payment statamic-payments').
 * What is its own are the personal access tokens. `payload()` carries ids
 * and names, never the token.
 */
abstract class ApiEvent
{
    use Dispatchable;

    /** The stable handle, e.g. `app-api.token.created`. Public contract. */
    abstract public static function handle(): string;

    /** @return array<string, mixed> */
    abstract public function payload(): array;
}
