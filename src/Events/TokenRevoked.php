<?php

namespace Goldnead\AppApi\Events;

class TokenRevoked extends ApiEvent
{
    /**
     * @param  string  $by  `user` (the owner) or `cp` (an admin in the Control Panel)
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly int $tokenId,
        public readonly string $name,
        public readonly string $by,
        public readonly ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'app-api.token.revoked';
    }

    public function payload(): array
    {
        return [
            'user' => ['id' => $this->userId, 'email' => $this->email],
            'token' => ['id' => $this->tokenId, 'name' => $this->name],
            'revoked_by' => $this->by,
            'actor_id' => $this->actorId,
        ];
    }
}
