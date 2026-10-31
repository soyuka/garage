<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Agent\Dispatch\TurnDispatcher;
use App\Message\RunAgentTurn;

final class RecordingTurnDispatcher implements TurnDispatcher
{
    /** @var list<RunAgentTurn> */
    public array $turns = [];

    public function dispatch(RunAgentTurn $turn): void
    {
        $this->turns[] = $turn;
    }
}
