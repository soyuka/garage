<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Journal\TurnJournal;
use App\Agent\Ui\RenderIntentTools;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;

/**
 * Ends every handled turn exactly once, on the journal and with a `turn end` frame, whatever killed
 * it: the handler settles its own loop; {@see Dispatch\TurnSettlementMiddleware} fails a turn that
 * threw before or outside that loop (the handler could not even be built); the shutdown hook fails one
 * a fatal error cut short (`set_time_limit` expiry in terminate mode, OOM in the worker).
 */
final class TurnSettlement
{
    private ?RunAgentTurn $open = null;
    private bool $settled = true;
    private bool $hooked = false;

    public function __construct(
        private readonly TurnJournal $journal,
        private readonly RenderIntentTools $renderTools,
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

    public function settle(AgentTurnStatus $status, ?string $error): void
    {
        $this->settled = true;
        $this->journal->finish($status, $error);
        $this->renderTools->signalTurnPhase('end', AgentTurnStatus::Failed === $status);
    }

    public function fail(RunAgentTurn $turn, string $error): void
    {
        if ($this->open !== $turn || $this->settled) {
            return;
        }
        $this->settled = true;

        // Frame first: after a timeout fatal, PHP only grants the shutdown path its hard_timeout (2 s).
        $this->renderTools->bindSession($turn->sessionTopic);
        $this->renderTools->signalTurnPhase('end', true);
        if (null === $this->journal->currentTurnId()) {
            $this->journal->begin($turn, '');
        }
        $this->journal->finish(AgentTurnStatus::Failed, $error);
    }

    public function close(): void
    {
        $this->open = null;
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
