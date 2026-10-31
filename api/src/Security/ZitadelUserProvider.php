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
 * Provisions {@see User} rows from validated Zitadel access-token claims (find-or-create
 * keyed on the `sub` claim). Roles come from Zitadel's project-roles claim, which is a map
 * of roleName => { orgId: orgDomain }; only the role names matter here.
 *
 * @implements AttributesBasedUserProviderInterface<User>
 */
final readonly class ZitadelUserProvider implements AttributesBasedUserProviderInterface
{
    private const string ROLES_CLAIM = 'urn:zitadel:iam:org:project:roles';

    private const array ROLE_MAP = [
        'Admin' => 'ROLE_ADMIN',
        'User' => 'ROLE_USER',
    ];

    public function __construct(
        private UserRepository $users,
        private DocumentManager $em,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): User
    {
        $email = isset($attributes['email']) && \is_string($attributes['email']) ? $attributes['email'] : null;
        $roles = $this->mapRoles($attributes[self::ROLES_CLAIM] ?? []);

        $user = $this->users->findOneByExternalId($identifier);
        if (null === $user) {
            $user = new User($identifier, $email, $roles);
            $this->em->persist($user);
        } else {
            $user->setEmail($email);
            $user->setRoles($roles);
        }

        $this->em->flush();

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

    /**
     * @param mixed $claim Zitadel project-roles claim: map of roleName => { orgId: orgDomain }
     *
     * @return list<string>
     */
    private function mapRoles(mixed $claim): array
    {
        $roles = ['ROLE_USER'];
        if (\is_array($claim)) {
            foreach (array_keys($claim) as $role) {
                if (\is_string($role) && isset(self::ROLE_MAP[$role])) {
                    $roles[] = self::ROLE_MAP[$role];
                }
            }
        }

        return array_values(array_unique($roles));
    }
}
