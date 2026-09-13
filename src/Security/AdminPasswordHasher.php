<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Accepts ADMIN_PASSWORD either as a bcrypt/argon2 hash or as plain text.
 */
final class AdminPasswordHasher implements PasswordHasherInterface
{
    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_BCRYPT);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if ('' === $hashedPassword || '' === $plainPassword) {
            return false;
        }
        if (str_starts_with($hashedPassword, '$2y$') || str_starts_with($hashedPassword, '$2a$') || str_starts_with($hashedPassword, '$2b$') || str_starts_with($hashedPassword, '$argon2')) {
            return password_verify($plainPassword, $hashedPassword);
        }

        return hash_equals($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}
