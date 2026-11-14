<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Journal\TurnJournal;
use App\Agent\Ui\RenderIntentTools;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ends every handled turn exactly once, on the journal and with a `turn end` frame, whatever killed
 * it: the handler settles its own loop; {@see Dispatch\TurnSettlementMiddleware} fails a turn that
 * threw before or outside that loop (the handler could not even be built); the shutdown hook fails one
 * a fatal error cut short (`set_time_limit` expiry in terminate mode, OOM in the worker).
 *
 * The journal is served over HTTP, so a crash is recorded as {@see self::PUBLIC_ERROR} plus an opaque
 * id; the cause (an exception message can carry a path, a DSN, an upstream's error text) only reaches
 * the log, under that id.
 */
final class TurnSettlement implements ResetInterface
{
    public const string PUBLIC_ERROR = 'The turn failed.';

    private ?RunAgentTurn $open = null;
    private bool $settled = true;
    private bool $hooked = false;

    public function __construct(
        private readonly TurnJournal $journal,
        private readonly RenderIntentTools $renderTools,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function open(RunAgentTurn $turn): void
    {
        $this->open = $turn;
        $this->settled = false;
        if (!$this->hooked) {
            $this->hooked = true;
            register_shutdown_function(fn () => $this->onShutdown(error_get_last()));
        }
    }

    /**
     * @param string|\Throwable|null $outcome a string is our own wording and is journalled as is; a
     *                                        throwable is a crash and is masked
     */
    public function settle(AgentTurnStatus $status, string|\Throwable|null $outcome): void
    {
        $this->settled = true;
        if ($outcome instanceof \Throwable) {
            $this->journal->finish($status, self::PUBLIC_ERROR, $this->report($outcome));
        } else {
            $this->journal->finish($status, $outcome);
        }
        $this->renderTools->signalTurnPhase('end', AgentTurnStatus::Failed === $status);
    }

    /**
     * @param \Throwable|string $cause always masked: whatever reaches here was not ours to word
     */
    public function fail(RunAgentTurn $turn, \Throwable|string $cause): void
    {
        if ($this->open !== $turn || $this->settled) {
            return;
        }
        $this->settled = true;

        // Frame first: after a timeout fatal, PHP only grants the shutdown path its hard_timeout (2 s).
        $this->renderTools->bindSession($turn->sessionTopic);
        try {
            $this->renderTools->signalTurnPhase('end', true);
            if (null === $this->journal->currentTurnId()) {
                $this->journal->begin($turn, '');
            }
            $this->journal->finish(AgentTurnStatus::Failed, self::PUBLIC_ERROR, $this->report($cause));
        } finally {
            $this->renderTools->bindSession(null);
        }
    }

    private function report(\Throwable|string $cause): string
    {
        $id = bin2hex(random_bytes(8));
        $this->logger->error('Agent turn failed [{errorId}]: {error}', [
            'errorId' => $id,
            'error' => $cause instanceof \Throwable ? $cause->getMessage() : $cause,
        ] + ($cause instanceof \Throwable ? ['exception' => $cause] : []));

        return $id;
    }

    public function close(): void
    {
        $this->open = null;
    }

    /** The shutdown hook stays registered: it is once per process, and reads `open` when it fires. */
    public function reset(): void
    {
        $this->open = null;
        $this->settled = true;
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $error
     */
    public function onShutdown(?array $error): void
    {
        if (null !== $this->open) {
            $this->fail($this->open, $error['message'] ?? 'Turn aborted before it settled.');
        }
    }
}
