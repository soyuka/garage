<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

use App\Message\RunAgentTurn;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerTurnDispatcher implements TurnDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatch(RunAgentTurn $turn): void
    {
        $this->bus->dispatch($turn);
    }
}
