<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What a journal row records: an agent turn, or a deterministic finder search (plate, VIN, category
 * narrowing) that never reached the model but still spent catalogue calls.
 */
enum AgentTurnKind: string
{
    case Agent = 'agent';
    case Search = 'search';
}
