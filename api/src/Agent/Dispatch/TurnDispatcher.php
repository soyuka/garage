<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

use App\Message\RunAgentTurn;

interface TurnDispatcher
{
    public function dispatch(RunAgentTurn $turn): void;
}
