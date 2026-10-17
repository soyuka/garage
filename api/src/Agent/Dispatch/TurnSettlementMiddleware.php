<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

use App\Agent\TurnSettlement;
use App\Message\RunAgentTurn;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Brackets the handling of a turn, in the worker and on the `sync` transport of terminate mode alike,
 * so a turn that throws where the handler cannot settle it still reaches a terminal frame.
 */
final readonly class TurnSettlementMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TurnSettlement $settlement,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $turn = $envelope->getMessage();
        if (!$turn instanceof RunAgentTurn || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->settlement->open($turn);
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $e) {
            $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
            $this->settlement->fail($turn, $cause->getMessage());

            throw $e;
        } finally {
            $this->settlement->close();
        }
    }
}
