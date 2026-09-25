<?php

namespace Goldnead\AppApi\Events;

class TokenCreated extends ApiEvent
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly int $tokenId,
        public readonly string $name,
        public readonly array $abilities,
        public readonly ?string $expiresAt,
    ) {}

    public static function handle(): string
    {
        return 'app-api.token.created';
    }

    public function payload(): array
    {
        return [
            'user' => ['id' => $this->userId, 'email' => $this->email],
            'token' => [
                'id' => $this->tokenId,
                'name' => $this->name,
                'abilities' => $this->abilities,
                'expires_at' => $this->expiresAt,
            ],
        ];
    }
}
