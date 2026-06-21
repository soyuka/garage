<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How an agent turn settled. `Completed` is the normal exit; `Capped` is the tool-call ceiling firing
 * (a model that would not converge — see {@see \App\DependencyInjection\Compiler\AgentMaxToolCallsPass});
 * `Failed` is any other throw inside the loop.
 */
enum AgentTurnStatus: string
{
    case Completed = 'completed';
    case Capped = 'capped';
    case Failed = 'failed';
}
