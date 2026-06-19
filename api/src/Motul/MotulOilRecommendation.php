<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * One oil/fluid recommendation row from a Motul "Oil Advisor" vehicle page. We keep only the
 * spec-bearing fields (application + viscosity + the ACEA/API-style quality); the SKU and product
 * line are carried for provenance but the tool surfaces the GRADE, not the Motul product, so the
 * advice stays brand-neutral.
 */
final readonly class MotulOilRecommendation
{
    public function __construct(
        public string $application,
        public string $viscosity,
        public string $quality,
        public string $productType,
        public string $quantity,
        public string $sku,
    ) {
    }

    public function isEngine(): bool
    {
        return 0 === strcasecmp($this->application, 'Engine');
    }
}
