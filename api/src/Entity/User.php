<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An application user provisioned from a Zitadel access token. `externalId` is the OIDC
 * `sub` claim — the stable identity seam — so re-authenticating the same Zitadel account
 * always resolves to the same row. Profile fields (email, roles) are refreshed from the
 * token on every request via {@see \App\Security\ZitadelUserProvider}.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '"user"')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        private string $externalId,
        #[ORM\Column(nullable: true)]
        private ?string $email = null,
        /** @var list<string> */
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getUserIdentifier(): string
    {
        return $this->externalId;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function eraseCredentials(): void
    {
    }
}
