<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class Representative
{
    /**
     * @param int $rank BrandTier::rank() of the brand, lower is better
     */
    public function __construct(
        public int $artId,
        public string $brand,
        public string $number,
        public int $rank,
        public Fingerprint $fingerprint,
    ) {
    }

    /** Best brand first, then a stable tie-break: the total order every deterministic step relies on. */
    public static function compare(self $a, self $b): int
    {
        return $a->rank <=> $b->rank
            ?: strcmp($a->number, $b->number)
            ?: $a->artId <=> $b->artId;
    }
}
