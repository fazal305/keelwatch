<?php

declare(strict_types=1);

namespace Keelwatch\Auth;

/** The signed-in user for one request. */
final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly string $csrfToken,
        public readonly string $sessionHash,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * @return array{id: int, username: string, role: string}
     */
    public function publicUser(): array
    {
        return ['id' => $this->userId, 'username' => $this->username, 'role' => $this->role];
    }

    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'username' => $this->username, 'role' => $this->role];
    }
}
