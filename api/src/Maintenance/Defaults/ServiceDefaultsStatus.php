<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

/**
 * The three honest outcomes of a baseline lookup. `Ambiguous` is the load-bearing one: several
 * baselines apply and the fact that would tell them apart is unknown on this vehicle, so the answer
 * is a QUESTION, never the average or the first of the candidates.
 */
enum ServiceDefaultsStatus: string
{
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case Unknown = 'unknown';
}
