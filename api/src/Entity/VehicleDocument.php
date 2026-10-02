<?php

declare(strict_types=1);

namespace App\Entity;

use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentSource;
use App\Repository\VehicleDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

// Pages are embedded: a document is read whole by the search scan, and a workshop manual's text stays
// far below Mongo's 16 MB document cap. Revisit (a separate pages collection) if a source ever exceeds it.
#[ODM\Document(collection: 'vehicle_documents', repositoryClass: VehicleDocumentRepository::class)]
#[ODM\Index(keys: ['owner' => 'asc', 'vehicle' => 'asc', 'addedAt' => 'desc'], name: 'idx_vehicle_document_owner_vehicle')]
class VehicleDocument
{
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $sourceRef = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $plannedTaskId = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $owner = null;

    /** @var Collection<int, VehicleDocumentPage> */
    #[ODM\EmbedMany(targetDocument: VehicleDocumentPage::class, strategy: 'set')]
    private Collection $pages;

    /**
     * @param list<VehicleDocumentPage> $pages
     */
    public function __construct(
        #[ODM\Id(strategy: 'NONE', type: 'string')]
        private string $id,
        #[ODM\ReferenceOne(targetDocument: Vehicle::class, storeAs: 'id')]
        private Vehicle $vehicle,
        #[ODM\Field(type: 'string', enumType: VehicleDocumentKind::class)]
        private VehicleDocumentKind $kind,
        #[ODM\Field(type: 'string')]
        private string $title,
        #[ODM\Field(type: 'string', enumType: VehicleDocumentSource::class)]
        private VehicleDocumentSource $source,
        #[ODM\Field(type: 'string')]
        private string $filename,
        #[ODM\Field(type: 'string')]
        private string $contentType,
        #[ODM\Field(type: 'int')]
        private int $size,
        #[ODM\Field(type: 'string')]
        private string $storageKey,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        ?string $sourceRef = null,
        ?string $plannedTaskId = null,
        ?string $owner = null,
        array $pages = [],
    ) {
        $this->sourceRef = $sourceRef;
        $this->plannedTaskId = $plannedTaskId;
        $this->owner = $owner;
        $this->pages = new ArrayCollection($pages);
    }

    /**
     * @param list<VehicleDocumentPage> $pages
     */
    public function replaceContent(VehicleDocumentKind $kind, string $title, string $filename, string $contentType, int $size, ?string $plannedTaskId, array $pages): void
    {
        $this->kind = $kind;
        $this->title = $title;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->plannedTaskId = $plannedTaskId;
        $this->pages = new ArrayCollection($pages);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getKind(): VehicleDocumentKind
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSource(): VehicleDocumentSource
    {
        return $this->source;
    }

    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    public function getPlannedTaskId(): ?string
    {
        return $this->plannedTaskId;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    /**
     * @return Collection<int, VehicleDocumentPage>
     */
    public function getPages(): Collection
    {
        return $this->pages;
    }
}
