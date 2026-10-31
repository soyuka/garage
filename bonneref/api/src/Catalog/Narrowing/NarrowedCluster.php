<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class NarrowedCluster
{
    /**
     * @param array<string, array<string, mixed>>                                                                                                                                                   $fingerprint
     * @param list<array{brand: string, number: string}>                                                                                                                                            $oeNumbers
     * @param array{artId: int, brand: string, number: string}                                                                                                                                      $representative
     * @param list<array{artId: int, brand: string, number: string, tier: int, applicability?: list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}>}> $variants       tier-ranked; under {@see ApplicabilityScope::Vehicle} each carries the conditions of its own link to the car, see {@see Applicability::ofLink()}
     * @param list<array{name: string, values: list<string>}>                                                                                                                                       $applicability  the union of the members' breakpoints under {@see ApplicabilityScope::Article}, the conditions every variant shares under {@see ApplicabilityScope::Vehicle}
     * @param array{id: int, name: string}|null                                                                                                                                                     $productGroup
     */
    public function __construct(
        public string $id,
        public array $fingerprint,
        public array $oeNumbers,
        public array $representative,
        public array $variants,
        public int $articleCount,
        public array $applicability = [],
        public ?array $productGroup = null,
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
