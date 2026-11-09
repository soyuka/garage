<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Provisions {@see User} documents from validated Casdoor access-token claims, find-or-create keyed on
 * the owner claim (`app.owner_claim`, the email). Runs on every authenticated request, so it writes
 * only on first sight or when the email changed. Roles are not read from the token: every user is
 * ROLE_USER and nothing here grants more.
 *
 * @implements AttributesBasedUserProviderInterface<User>
 */
final readonly class CasdoorUserProvider implements AttributesBasedUserProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private DocumentManager $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): User
    {
        $email = isset($attributes['email']) && \is_string($attributes['email']) ? $attributes['email'] : null;

        $user = $this->users->findOneByExternalId($identifier);
        if (null === $user) {
            $user = new User($identifier, $email);
            $this->documents->persist($user);
        } elseif ($user->getEmail() !== $email) {
            $user->setEmail($email);
        } else {
            return $user;
        }

        $this->documents->flush();

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->users->findOneByExternalId($user->getUserIdentifier()) ?? $user;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
