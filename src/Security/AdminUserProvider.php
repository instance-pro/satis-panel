<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Single admin account configured through ADMIN_USER / ADMIN_PASSWORD.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final class AdminUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly string $adminUser,
        private readonly string $adminPassword,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ('' === $this->adminPassword || $identifier !== $this->adminUser) {
            $exception = new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return new InMemoryUser($this->adminUser, $this->adminPassword, ['ROLE_ADMIN']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class || is_subclass_of($class, InMemoryUser::class);
    }
}
