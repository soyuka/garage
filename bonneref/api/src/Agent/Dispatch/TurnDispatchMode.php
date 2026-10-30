<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

enum TurnDispatchMode: string
{
    case Messenger = 'messenger';
    case Terminate = 'terminate';
}
