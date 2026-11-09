<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An application user provisioned from a Casdoor access token. `externalId` is the owner claim
 * (`app.owner_claim`: the verified email, since Casdoor re-mints `sub`), so re-authenticating the
 * same account resolves to the same document. {@see \App\Security\CasdoorUserProvider} keeps the
 * email current.
 */
#[ODM\Document(collection: 'users', repositoryClass: UserRepository::class)]
class User implements UserInterface
{
    #[ODM\Id]
    private ?string $id = null;

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

    public function eraseCredentials(): void
    {
    }
}
