<?php

/*
 * Stand-in for goldnead/statamic-activity's facade: `record()` with the real
 * signature, holding what it got.
 */

namespace Goldnead\Activity\Facades {
    if (! class_exists(Activity::class)) {
        class Activity
        {
            /** @var list<array{type: string, attributes: array<string, mixed>}> */
            public static array $recorded = [];

            /** @param  array<string, mixed>  $attributes */
            public static function record(string $eventType, array $attributes = []): null
            {
                static::$recorded[] = ['type' => $eventType, 'attributes' => $attributes];

                return null;
            }
        }
    }
}
