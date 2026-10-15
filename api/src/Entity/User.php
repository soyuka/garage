<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An application user provisioned from a Casdoor access token. `externalId` is the OIDC
 * `sub` claim — the stable identity seam — so re-authenticating the same account always
 * resolves to the same document. Profile fields (email, roles) are refreshed from the
 * token on every request via {@see \App\Security\ZitadelUserProvider}.
 */
#[ODM\Document(collection: 'users', repositoryClass: UserRepository::class)]
class User implements UserInterface
{
    #[ODM\Id]
    private ?string $id = null;

    // Class-level default: a constructor-promoted nullable field 500s on documents written before it existed.
    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $historyClearedAt = null;

    public function __construct(
        #[ODM\Field(type: 'string')]
        #[ODM\UniqueIndex]
        private string $externalId,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $email = null,
        /** @var list<string> */
        #[ODM\Field(type: 'collection')]
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): ?string
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

    /** Finder home history: journal rows started before this moment are hidden from it. */
    public function getHistoryClearedAt(): ?\DateTimeImmutable
    {
        return $this->historyClearedAt;
    }

    public function setHistoryClearedAt(?\DateTimeImmutable $historyClearedAt): void
    {
        $this->historyClearedAt = $historyClearedAt;
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
