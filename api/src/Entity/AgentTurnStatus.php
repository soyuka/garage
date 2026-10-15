<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How an agent turn settled. `Completed` is the normal exit; `Capped` is the tool-call ceiling firing
 * (a model that would not converge — see `max_tool_calls`);
 * `Failed` is any other throw inside the loop; `NoOp` is a turn that ran to a clean exit but called no
 * write/render/terminal tool — a model that "answered" in prose the generative-UI has no channel for,
 * so nothing was persisted or shown (see {@see \App\Agent\Ui\TurnActivityTracker}).
 */
enum AgentTurnStatus: string
{
    case Completed = 'completed';
    case Capped = 'capped';
    case Failed = 'failed';
    case NoOp = 'noop';
}
