<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class NarrowedCluster
{
    /**
     * @param array<string, array<string, mixed>>                          $fingerprint
     * @param list<array{brand: string, number: string}>                $oeNumbers
     * @param array{artId: int, brand: string, number: string} $representative
     * @param list<array{artId: int, brand: string, number: string, tier: int}>   $variants       tier-ranked
     */
    public function __construct(
        public string $id,
        public array $fingerprint,
        public array $oeNumbers,
        public array $representative,
        public array $variants,
        public int $articleCount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
