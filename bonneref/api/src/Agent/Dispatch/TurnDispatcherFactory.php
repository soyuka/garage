<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

final class TurnDispatcherFactory
{
    public static function create(TurnDispatchMode $mode, MessengerTurnDispatcher $messenger, DeferredTurnDispatcher $deferred): TurnDispatcher
    {
        return match ($mode) {
            TurnDispatchMode::Messenger => $messenger,
            TurnDispatchMode::Terminate => $deferred,
        };
    }
}
