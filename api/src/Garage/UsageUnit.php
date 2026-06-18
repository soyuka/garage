<?php

declare(strict_types=1);

namespace App\Garage;

/**
 * Unit a vehicle's usage reading is measured in. Road vehicles accrue distance (`km`/`mi`);
 * ground-care machinery (mowers, generators like the TORO 246-H) accrues engine `h` — so the
 * unit is stored explicitly and never assumed to be kilometers.
 */
enum UsageUnit: string
{
    case Kilometers = 'km';
    case Miles = 'mi';
    case Hours = 'h';
}
