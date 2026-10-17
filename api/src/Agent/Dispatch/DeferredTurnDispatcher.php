<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

use App\Message\RunAgentTurn;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Runs the turn in kernel.terminate of the request that asked for it, once the 202 is on the wire,
 * so a deployment without a `messenger:consume` worker still answers. Under FrankenPHP worker mode
 * the runner calls terminate() after frankenphp_handle_request() returned, i.e. after the response
 * was flushed; the PHP thread stays busy for the turn's duration.
 *
 * It goes through the bus on the `sync` transport rather than calling the handler, so the Messenger
 * middleware stack still applies, {@see TurnSettlementMiddleware} included. A failed turn is logged
 * and not rethrown: it is already journalled and ended with a failed `turn end` frame, and there is
 * neither a retry nor a dead letter here — re-running an expensive LLM turn in the request thread is
 * not worth it. A turn the time limit kills never reaches the catch: the settlement's shutdown hook
 * ends that one.
 */
final class DeferredTurnDispatcher implements TurnDispatcher
{
    /** @var list<RunAgentTurn> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(int:AGENT_TURN_TIME_LIMIT)%')]
        private readonly int $timeLimit,
    ) {
    }

    public function dispatch(RunAgentTurn $turn): void
    {
        $this->pending[] = $turn;
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    public function onTerminate(): void
    {
        if ([] === $this->pending) {
            return;
        }

        [$turns, $this->pending] = [$this->pending, []];

        ignore_user_abort(true);
        $previousLimit = (int) \ini_get('max_execution_time');
        try {
            foreach ($turns as $turn) {
                set_time_limit($this->timeLimit);
                try {
                    $this->bus->dispatch($turn, [new TransportNamesStamp(['sync'])]);
                } catch (\Throwable $e) {
                    $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
                    $this->logger->error('Deferred agent turn failed: {error}', ['error' => $cause->getMessage(), 'exception' => $e]);
                }
            }
        } finally {
            // In FrankenPHP worker mode this thread outlives the request: do not leave it on the turn's limit.
            set_time_limit($previousLimit);
        }
    }
}
