<?php

namespace Goldnead\AppApi\Http;

use Goldnead\Accounts\Exceptions\AccountException;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\Teams\Exceptions\TeamsException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every refusal under the prefix, in one shape:
 *
 *     {"error": {"code": "not_member", "message": "…", "field": "team", "details": {…}}}
 *
 * `code` is stable and meant for the client's `switch`; `message` is
 * translated and meant for a person; `field` names the input, `details` what
 * the client needs to act (blockers, the missing product, `retry_after`).
 *
 * The siblings' own refusals are read by class name, so none of them is
 * needed to load this class: a `TeamsException` keeps its `reason` as the
 * code and its status, an `AccountException` becomes 403 (impersonation),
 * 422 (address) or 409 (anything else about the account).
 */
class ErrorRenderer
{
    /** @var array<int, string> */
    public const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        402 => 'payment_required',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'gone',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        423 => 'locked',
        429 => 'too_many_requests',
        500 => 'server_error',
        503 => 'unavailable',
    ];

    public const TEAMS_EXCEPTION = 'Goldnead\Teams\Exceptions\TeamsException';

    public const ACCOUNT_EXCEPTION = 'Goldnead\Accounts\Exceptions\AccountException';

    public const ELEVATION_EXCEPTION = 'Statamic\Exceptions\ElevatedSessionAuthorizationException';

    public function render(Throwable $e, Request $request): JsonResponse|Response
    {
        // A response thrown by core or a sibling. A redirect means "go back
        // to the form", which a JSON client has not got: a refusal. Any
        // other response is already an answer and goes out as it is.
        if ($e instanceof HttpResponseException) {
            $response = $e->getResponse();

            if (! $response->isRedirection()) {
                return $response;
            }

            $errors = $request->hasSession() ? $request->session()->get('errors') : null;
            $message = $errors instanceof ViewErrorBag ? (string) $errors->first() : '';

            return self::respond(422, 'request_refused', $message !== '' ? $message : $this->message('request_refused'));
        }

        [$status, $code, $message, $field, $details, $headers] = $this->describe($e, $request);

        return self::respond($status, $code, $message, $field, $details, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function respond(int $status, string $code, string $message, ?string $field = null, array $details = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($field !== null) {
            $error['field'] = $field;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string|null, 4: array<string, mixed>, 5: array<string, string>}
     */
    protected function describe(Throwable $e, Request $request): array
    {
        if ($e instanceof ApiException) {
            return [$e->status, $e->errorCode, $e->getMessage(), $e->field, $e->details, []];
        }

        if (is_a($e, self::TEAMS_EXCEPTION)) {
            /** @var TeamsException $e */
            return [$e->status(), $e->reason, $e->getMessage(), $this->teamsField($e->reason), [], []];
        }

        if (is_a($e, self::ACCOUNT_EXCEPTION)) {
            /** @var AccountException $e */
            return match ($e->field) {
                'impersonation' => [403, 'impersonation_locked', $e->getMessage(), null, [], []],
                'email' => [422, 'email_rejected', $e->getMessage(), 'email', [], []],
                default => [409, 'account_refused', $e->getMessage(), $e->field, [], []],
            };
        }

        if (is_a($e, self::ELEVATION_EXCEPTION)) {
            return [423, 'elevation_required', $this->message('elevation_required'), null, $this->elevationDetails(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', $this->message('unauthenticated'), null, [], []];
        }

        if ($e instanceof AuthorizationException) {
            return [403, 'forbidden', $this->message('forbidden'), null, [], []];
        }

        if ($e instanceof ValidationException) {
            $errors = $e->errors();
            $field = array_key_first($errors);
            $first = $field === null ? $e->getMessage() : (string) ($errors[$field][0] ?? $e->getMessage());

            // Statamic's login throttle is a validation error with status 429.
            if ($e->status === 429) {
                return [429, 'too_many_attempts', $first, $field === null ? null : (string) $field, [], []];
            }

            return [422, 'validation_failed', $first, $field === null ? null : (string) $field, ['errors' => $errors], []];
        }

        if ($e instanceof ThrottleRequestsException) {
            $headers = array_map(fn ($value) => (string) (is_array($value) ? reset($value) : $value), $e->getHeaders());

            return [429, 'too_many_requests', $this->message('too_many_requests'), null, array_filter([
                'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
            ]), $headers];
        }

        if ($e instanceof ModelNotFoundException) {
            return [404, 'not_found', $this->message('not_found'), null, [], []];
        }

        if ($e instanceof TokenMismatchException) {
            return [419, 'csrf_token_mismatch', $this->message('csrf_token_mismatch'), null, [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::CODES[$status] ?? 'http_'.$status;
            $message = $e->getMessage() !== '' ? $e->getMessage() : $this->message($code);

            return [$status, $code, $message, null, [], array_map('strval', $e->getHeaders())];
        }

        // A request outside `sanctum.stateful` that reached code needing the
        // session (RequireSession covers the known endpoints; this is the net).
        if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'Session store not set')) {
            return [400, 'stateful_origin_required', $this->message('stateful_origin_required'), null, ['config' => 'sanctum.stateful'], []];
        }

        $message = config('app.debug') ? $e->getMessage() : $this->message('server_error');

        return [500, 'server_error', $message, null, [], []];
    }

    protected function message(string $code): string
    {
        $key = "app-api::errors.{$code}";
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : str_replace('_', ' ', ucfirst($code));
    }

    protected function teamsField(string $reason): ?string
    {
        return match (true) {
            str_starts_with($reason, 'invitation') => 'invitation',
            str_starts_with($reason, 'join') => 'code',
            $reason === 'unknown_role' => 'role',
            default => null,
        };
    }

    /**
     * How this user confirms: `password_confirmation`, `verification_code`
     * (a code by mail, sent with POST session/elevation/code) or `passkey`.
     *
     * @return array<string, mixed>
     */
    protected function elevationDetails(): array
    {
        $user = User::current();
        $prefix = trim((string) config('app-api.routes.prefix', 'api/app'), '/');

        return array_filter([
            'method' => $user && method_exists($user, 'getElevatedSessionMethod') ? $user->getElevatedSessionMethod() : null,
            'confirm_url' => url($prefix.'/session/elevation'),
        ]);
    }
}
