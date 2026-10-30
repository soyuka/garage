<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Which client asked for the turn. Persisted on every journal row (and read back by the history
 * queries), so it stays an enum even though the finder is the only surface this app serves.
 */
enum TurnSurface: string
{
    case Finder = 'finder';
}
