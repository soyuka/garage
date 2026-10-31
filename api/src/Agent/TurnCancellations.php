<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * A cross-process "stop this turn" flag, keyed by session id. The cancel HTTP request lands in the
 * web container while the turn runs in the messenger worker (a separate process), so the flag must
 * live in a store both share.
 *
 * The flag is advisory and cooperative: {@see Ui\CancelTurnListener} polls it at each
 * tool-call boundary and ends the turn cleanly. A short TTL is the backstop — a cancel that races a
 * turn which already finished simply expires instead of lingering to kill an unrelated later turn;
 * the handler also clears the flag at the start of every turn for the same reason.
 */
interface TurnCancellations
{
    public function request(string $sessionId): void;

    public function isRequested(string $sessionId): bool;

    public function clear(string $sessionId): void;
}
