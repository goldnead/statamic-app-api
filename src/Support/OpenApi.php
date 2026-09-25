<?php

namespace Goldnead\AppApi\Support;

/**
 * The OpenAPI 3.1 description, built from {@see Endpoints} and
 * {@see ErrorCodes}, so it cannot drift from the routes.
 *
 * `build(activeOnly: true)` describes what this site offers (served at
 * {prefix}/openapi.json); `activeOnly: false` describes every area, which
 * is what `php artisan app-api:openapi` writes and what the repository
 * ships as `openapi.json`.
 */
class OpenApi
{
    /** @return array<string, mixed> */
    public static function build(bool $activeOnly = true, ?string $serverUrl = null): array
    {
        $endpoints = $activeOnly ? Endpoints::active() : Endpoints::all();
        $paths = [];

        foreach ($endpoints as $endpoint) {
            $path = Endpoints::path((string) $endpoint['uri']);
            $paths[$path][strtolower((string) $endpoint['method'])] = self::operation($endpoint);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Statamic App API',
                'version' => '1.0.0',
                'description' => "JSON for single-page apps on Statamic, over Laravel Sanctum.\n\n"
                    .'Session: fetch `/sanctum/csrf-cookie` once, then send the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN` '
                    ."with every write (axios does this with `withCredentials: true`). Tokens: `Authorization: Bearer <token>`.\n\n"
                    .'Every error has the shape `{error: {code, message, field?, details?}}`. `code` is stable.',
            ],
            'servers' => [['url' => $serverUrl ?? '/']],
            'tags' => array_map(fn (string $area) => ['name' => $area, 'description' => 'Needs '.Areas::PACKAGES[$area].'.'], Areas::all()),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string) config('session.cookie', 'laravel_session')],
                    'token' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'parameters' => [
                    'TeamHeader' => [
                        'name' => self::teamHeader(),
                        'in' => 'header',
                        'required' => false,
                        'description' => 'The team this request is about, by id or uuid. A team the user is not in: 403 not_member.',
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    protected static function operation(array $endpoint): array
    {
        $operation = [
            'operationId' => str_replace(['.', '-'], '_', (string) $endpoint['name']),
            'tags' => [$endpoint['area']],
            'summary' => $endpoint['summary'],
        ];

        if (! empty($endpoint['description'])) {
            $operation['description'] = $endpoint['description'];
        }

        $parameters = [];

        if (preg_match_all('/\{(\w+)\}/', (string) $endpoint['uri'], $matches)) {
            foreach ($matches[1] as $name) {
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
        }

        foreach ((array) $endpoint['query'] as $name => $type) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => ! str_starts_with($type, '?'), 'schema' => self::type($type)];
        }

        if ($endpoint['team']) {
            $parameters[] = ['$ref' => '#/components/parameters/TeamHeader'];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($endpoint['body'] !== []) {
            $properties = [];
            $required = [];

            foreach ((array) $endpoint['body'] as $name => $type) {
                $properties[$name] = self::type($type);

                if (! str_starts_with($type, '?')) {
                    $required[] = $name;
                }
            }

            $schema = ['type' => 'object', 'properties' => $properties];

            if ($required !== []) {
                $schema['required'] = $required;
            }

            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
        }

        if ($endpoint['auth']) {
            $operation['security'] = [['session' => []], ['token' => []]];
        }

        $flags = array_keys(array_filter(['auth' => $endpoint['auth'], 'elevated' => $endpoint['elevated'], 'team' => $endpoint['team']]));

        if ($flags !== []) {
            $operation['x-app-api'] = $flags;
        }

        $status = (string) $endpoint['status'];
        $responses = [];

        if ($endpoint['response'] === 'File') {
            $responses[$status] = ['description' => 'The file.', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
        } elseif ($endpoint['response'] === null || (int) $status === 204) {
            $responses[$status] = ['description' => 'Done, no content.'];
        } else {
            $responses[$status] = ['description' => 'Success.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$endpoint['response']]]]];
        }

        $errors = (array) $endpoint['errors'];

        if ($endpoint['auth']) {
            array_push($errors, 'unauthenticated', 'tokens_disabled', 'token_ability_missing');

            if (! $endpoint['setup']) {
                $errors[] = 'two_factor_setup_required';
            }
        }

        if ($endpoint['session']) {
            $errors[] = 'stateful_origin_required';
        }

        $byStatus = [];

        foreach (array_unique($errors) as $code) {
            $byStatus[ErrorCodes::status($code)][] = $code;
        }

        ksort($byStatus);

        foreach ($byStatus as $errorStatus => $codes) {
            $responses[(string) $errorStatus] = [
                'description' => 'Error: '.implode(', ', $codes).'.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /** @return array<string, mixed> */
    protected static function type(string $type): array
    {
        return match (ltrim($type, '?')) {
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'object' => ['type' => 'object'],
            default => ['type' => 'string'],
        };
    }

    protected static function teamHeader(): string
    {
        $header = config('app-api.teams.header');

        return is_string($header) && $header !== '' ? $header : (string) config('teams.current.header', 'X-Team-ID');
    }

    /** @return array<string, mixed> */
    protected static function schemas(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $nullableInt = ['type' => ['integer', 'null']];
        $bool = ['type' => 'boolean'];
        $id = ['type' => ['string', 'integer']];
        $ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
        $object = fn (array $properties, array $required = []) => array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required ?: null]);
        $list = fn (array $item) => ['type' => 'array', 'items' => $item];

        $quota = $object([
            'key' => $string, 'kind' => ['type' => 'string', 'enum' => ['usage', 'stock']], 'limit' => $nullableInt,
            'unlimited' => $bool, 'used' => $nullableInt, 'remaining' => $nullableInt, 'period' => $nullableString,
            'period_start' => $nullableString, 'period_end' => $nullableString, 'holder' => $nullableString,
            'source' => ['type' => 'string', 'enum' => ['grant', 'fallback', 'none']], 'product' => $nullableString,
        ]);

        $decision = $object(['allowed' => $bool, 'reason' => ['type' => 'string', 'enum' => ['ENTITLED', 'NOT_ENTITLED']], 'state' => $nullableString]);

        $team = $object([
            'id' => $int, 'uuid' => $string, 'name' => $string, 'type' => $string, 'owner_id' => $nullableString,
            'role' => $nullableString, 'permissions' => $list($string), 'is_current' => $bool, 'is_personal' => $bool,
            'read_only' => $bool, 'join_method' => $string, 'join_code' => $nullableString,
        ], ['id', 'uuid', 'name', 'role']);

        $invitation = $object([
            'id' => $int, 'uuid' => $string, 'team_id' => $int, 'email' => $string, 'role' => $string,
            'status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'revoked', 'expired']], 'expires_at' => $nullableString,
            'url' => ['type' => 'string', 'description' => 'Only in the answer to the invitation itself.'],
        ]);

        $token = $object([
            'id' => $int, 'name' => $string, 'abilities' => $list($string), 'last_used_at' => $nullableString,
            'expires_at' => $nullableString, 'created_at' => $nullableString,
        ]);

        $payment = $object([
            'id' => $int, 'status' => ['type' => 'string', 'enum' => ['initiated', 'open', 'paid', 'failed', 'expired', 'canceled', 'refunded']],
            'paid' => $bool, 'product' => $string, 'amount_cent' => $int, 'currency' => $string, 'team_id' => $nullableInt,
        ]);

        return [
            'Error' => $object(['error' => $object([
                'code' => ['type' => 'string', 'enum' => array_keys(ErrorCodes::STATUS)],
                'message' => $string,
                'field' => $string,
                'details' => ['type' => 'object'],
            ], ['code', 'message'])], ['error']),
            'User' => $object([
                'id' => $id, 'email' => $string, 'name' => $nullableString, 'initials' => $string, 'avatar' => $nullableString,
                'super' => $bool, 'email_verified' => ['type' => ['boolean', 'null']], 'email_verified_at' => $nullableString,
                'two_factor_enabled' => $bool, 'current_team_id' => $nullableInt,
            ], ['id', 'email']),
            'UserEnvelope' => $object(['user' => ['oneOf' => [$ref('User'), ['type' => 'null']]]], ['user']),
            'LoginResult' => $object(['two_factor' => $bool, 'two_factor_setup_required' => $bool, 'user' => ['oneOf' => [$ref('User'), ['type' => 'null']]]], ['two_factor', 'two_factor_setup_required', 'user']),
            'TwoFactorSetup' => $object(['qr' => ['type' => 'string', 'description' => 'SVG'], 'secret_key' => $string, 'confirm_url' => $string]),
            'RecoveryCodes' => $object(['recovery_codes' => $list($string)]),
            'TwoFactorOff' => $object(['two_factor_setup_required' => $bool]),
            'CheckoutTerms' => $object([
                'product' => $nullableString, 'offer' => $nullableString, 'digital' => $bool,
                'consent_text' => ['type' => ['string', 'null'], 'description' => 'Shown next to the checkbox. Null: no consent text, the checkbox confirms the order only.'],
                'consent_version' => ['type' => 'string', 'description' => 'Send back as consent_version with POST checkout.'],
                'button_label' => ['type' => 'string', 'description' => 'The label of the order button (§ 312j Abs. 3 BGB).'],
            ], ['digital', 'consent_text', 'consent_version', 'button_label']),
            'Message' => $object(['message' => $string]),
            'Sent' => $object(['sent' => $bool]),
            'Elevation' => $object([
                'enabled' => $bool, 'elevated' => $bool, 'expires_at' => $nullableString,
                'method' => ['type' => 'string', 'enum' => ['password_confirmation', 'verification_code', 'passkey']], 'passkey' => $bool,
            ]),
            'WebAuthnOptions' => ['type' => 'object', 'description' => 'PublicKeyCredentialRequestOptions, as Statamic serialises them.'],
            'Meta' => $object([
                'areas' => ['type' => 'object', 'additionalProperties' => $bool], 'prefix' => $string, 'team_header' => $string,
                'csrf_cookie_url' => $string, 'two_factor' => $bool, 'elevated_sessions' => $bool, 'registration' => $bool,
                'openapi_url' => $nullableString,
            ]),
            'OpenApi' => ['type' => 'object'],
            'Account' => $object(['account' => $object([
                'email' => $string,
                'verification' => $object(['enabled' => $bool, 'verified' => $bool, 'verified_at' => $nullableString]),
                'email_change' => ['oneOf' => [$object(['email' => $string, 'expires_at' => $nullableString]), ['type' => 'null']]],
                'deletion' => ['oneOf' => [$object(['status' => ['type' => 'string', 'enum' => ['pending', 'blocked']], 'due_at' => $nullableString]), ['type' => 'null']]],
                'deletion_blockers' => $list($string), 'grace_days' => $int, 'export_enabled' => $bool, 'impersonated' => $bool,
            ])]),
            'Export' => $object(['download_url' => $string, 'filename' => $string, 'expires_at' => $string]),
            'Team' => $team,
            'TeamEnvelope' => $object(['team' => $ref('Team')], ['team']),
            'TeamList' => $object(['data' => $list($ref('Team')), 'current_team_id' => $nullableInt]),
            'Member' => $object([
                'user' => $object(['id' => $id, 'email' => $nullableString, 'name' => $nullableString, 'avatar' => $nullableString]),
                'role' => $string, 'is_owner' => $bool, 'meta' => ['type' => 'object'], 'joined_at' => $nullableString,
            ]),
            'MemberEnvelope' => $object(['member' => $ref('Member')]),
            'MemberList' => $object(['data' => $list($ref('Member'))]),
            'Invitation' => $invitation,
            'InvitationEnvelope' => $object(['invitation' => $ref('Invitation')]),
            'InvitationList' => $object(['data' => $list($ref('Invitation'))]),
            'InvitationPreview' => $object(['invitation' => $object([
                'team' => $object(['id' => $int, 'uuid' => $string, 'name' => $string]),
                'email' => $string, 'role' => $string, 'status' => $string, 'expires_at' => $nullableString,
            ])]),
            'JoinCode' => $object(['join_code' => $string]),
            'Quota' => $quota,
            'AccessDecision' => $decision,
            'Access' => $object([
                'user' => $object(['products' => $list($string), 'quotas' => ['type' => 'object', 'additionalProperties' => $ref('Quota')]]),
                'team' => ['oneOf' => [$object(['id' => $int, 'products' => $list($string), 'quotas' => ['type' => 'object', 'additionalProperties' => $ref('Quota')]]), ['type' => 'null']]],
            ]),
            'ProductAccess' => $object([
                'product' => $string, 'allowed' => $bool, 'user' => $ref('AccessDecision'),
                'team' => ['oneOf' => [$ref('AccessDecision'), ['type' => 'null']]],
            ]),
            'QuotaAccess' => $object(['key' => $string, 'user' => $ref('Quota'), 'team' => ['oneOf' => [$ref('Quota'), ['type' => 'null']]]]),
            'Payment' => $payment,
            'Checkout' => $object(['checkout_url' => $string, 'reused' => $bool, 'payment' => $ref('Payment')], ['checkout_url', 'payment']),
            'CheckoutStatus' => $object(['payment' => $ref('Payment')]),
            'PortalLink' => $object(['url' => $string, 'expires_at' => $string]),
            'Token' => $token,
            'TokenList' => $object(['data' => $list($ref('Token'))]),
            'NewToken' => $object(['token' => $ref('Token'), 'plain_text_token' => $string]),
        ];
    }
}
