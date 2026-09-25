<?php

namespace Goldnead\AppApi\Exceptions;

use Goldnead\AppApi\Http\ErrorRenderer;
use Illuminate\Http\JsonResponse;
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

    /**
     * A 4xx is the client's mistake and already answered; it does not belong
     * in the error log, where anyone could fill it through the public API.
     * `true` tells Laravel the report is handled, `false` lets it log a 5xx.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }

    /**
     * Renders itself in the one error shape, also on a site's own routes
     * that carry one of this addon's middlewares but not `app-api.json`.
     */
    public function render(): JsonResponse
    {
        return ErrorRenderer::respond($this->status, $this->errorCode, $this->getMessage(), $this->field, $this->details);
    }
}
