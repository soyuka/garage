<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * The variant the finder agent retained (show_parts clusterId), stored when it selects so the finder
 * route carries only this id: a shared link shows the server's basis, never text taken from the URL.
 * Read back by id through {@see \App\Controller\Finder\FinderSelectionController}, public fields only.
 */
#[ODM\Document(collection: 'finder_selections')]
class FinderSelection
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    public private(set) string $id;

    #[ODM\Field(type: 'date_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        #[ODM\Field(type: 'string', nullable: true)]
        public private(set) ?string $owner,
        #[ODM\Field(type: 'string', nullable: true)]
        public private(set) ?string $turnId,
        #[ODM\Field(type: 'int')]
        public private(set) int $carId,
        #[ODM\Field(type: 'string')]
        public private(set) string $carType,
        #[ODM\Field(type: 'int')]
        public private(set) int $categoryId,
        #[ODM\Field(type: 'int', nullable: true)]
        public private(set) ?int $productGroupId,
        #[ODM\Field(type: 'string')]
        public private(set) string $clusterId,
        #[ODM\Field(type: 'string')]
        public private(set) string $basis,
        #[ODM\Field(type: 'string', nullable: true)]
        public private(set) ?string $setAside,
        #[ODM\Field(type: 'string', nullable: true)]
        public private(set) ?string $organisationNumber,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = (string) new Ulid();
        $this->createdAt = $createdAt;
    }

    /**
     * @return array{id: string, carId: int, carType: string, categoryId: int, productGroupId: ?int, clusterId: string, basis: string, setAside: ?string}
     */
    public function publicView(): array
    {
        return [
            'id' => $this->id,
            'carId' => $this->carId,
            'carType' => $this->carType,
            'categoryId' => $this->categoryId,
            'productGroupId' => $this->productGroupId,
            'clusterId' => $this->clusterId,
            'basis' => $this->basis,
            'setAside' => $this->setAside,
        ];
    }
}
