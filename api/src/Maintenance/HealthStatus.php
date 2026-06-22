<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * A vehicle's at-a-glance maintenance health, composed from the forecast. `Unknown` is first-class:
 * a vehicle with no schedule and no history is honestly unknown, never a false `Green`.
 */
enum HealthStatus: string
{
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
    case Unknown = 'unknown';
}
