<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Which client asked for the turn. Each surface runs its own agent, prompt and toolset; the turn
 * lifecycle (owner, session topic, cancel, journal, guardrails) is shared.
 */
enum TurnSurface: string
{
    case Garage = 'garage';
    case Finder = 'finder';
}
