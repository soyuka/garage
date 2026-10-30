<?php

declare(strict_types=1);

namespace App\Knowledge\BuildCode;

final readonly class DecodedBuildCode
{
    /**
     * @param string       $kind  opr|dam|apv for a decoded code, date for a reversed date
     * @param list<string> $caveats
     * @param list<string> $sources
     */
    public function __construct(
        public string $kind,
        public string $organisationNumber,
        public \DateTimeImmutable $date,
        public ?string $plantCode = null,
        public ?string $plantName = null,
        public ?string $sequence = null,
        public array $caveats = [],
        public array $sources = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'codeKind' => $this->kind,
            'organisationNumber' => $this->organisationNumber,
            'date' => $this->date->format('Y-m-d'),
            'plantCode' => $this->plantCode,
            'plantName' => $this->plantName,
            'sequence' => $this->sequence,
            'caveats' => $this->caveats,
            'sources' => $this->sources,
        ];
    }
}
