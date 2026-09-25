<?php

namespace Goldnead\AppApi\Exceptions;

use RuntimeException;

/**
 * A refusal this addon answers itself, in the one error shape
 * `{error: {code, message, field?}}`.
 *
 * `code` is the stable machine name (public contract, listed in the README
 * and the OpenAPI description), `status` the HTTP status, `field` the input
 * it is about, `details` anything a client needs to act on it (the blockers
 * of a deletion, the product that is missing).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        ?string $message = null,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message ?? (string) __("app-api::errors.{$errorCode}"));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $code, int $status, ?string $field = null, array $details = [], ?string $message = null): self
    {
        return new self($code, $status, $message, $field, $details);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }
}
