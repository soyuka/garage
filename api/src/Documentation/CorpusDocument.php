<?php

declare(strict_types=1);

namespace App\Documentation;

final readonly class CorpusDocument
{
    /**
     * @param list<array{number: int, label: ?string, text: string}> $pages
     */
    public function __construct(
        public string $id,
        public string $title,
        public VehicleDocumentKind $kind,
        public ?string $sourceRef,
        public \DateTimeImmutable $addedAt,
        public array $pages,
    ) {
    }
}
