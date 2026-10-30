<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\PartPhotoHints;
use App\RegistrationDocument\RegistrationDocumentExtraction;

final readonly class PhotoIntakeResult
{
    public function __construct(
        public PhotoKind $kind,
        public ?RegistrationDocumentExtraction $registration = null,
        public ?PartPhotoHints $part = null,
        public ?BuildLabelHints $buildLabel = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'registration' => $this->registration?->toArray(),
            'part' => $this->part?->toArray(),
            'buildLabel' => $this->buildLabel?->toArray(),
        ];
    }
}
