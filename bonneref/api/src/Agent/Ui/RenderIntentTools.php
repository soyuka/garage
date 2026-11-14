<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

/**
 * Publishes the turn's frames (lifecycle, status line, toast, and the finder's own frames built by
 * {@see \App\Agent\Finder\FinderRenderTools}) to the per-session Mercure topic bound for the turn,
 * so only the requesting tab reacts. Not tools: the LLM never calls these directly.
 */
final class RenderIntentTools implements ResetInterface
{
    /** @var array{type: 'toast', tone: string, message: string}|null */
    private ?array $pendingToast = null;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly DashboardChannel $channel,
    ) {
    }

    /**
     * Binds the per-turn session topic. Called by {@see \App\MessageHandler\RunAgentTurnHandler}
     * before the agent runs; a turn is one message, so there is no cross-turn bleed.
     */
    public function bindSession(?string $sessionTopic): void
    {
        $this->channel->bindSession($sessionTopic);
    }

    public function reset(): void
    {
        $this->pendingToast = null;
    }

    /** @see DashboardChannel::sessionId() */
    public function sessionId(): ?string
    {
        return $this->channel->sessionId();
    }

    /**
     * Streams a turn-lifecycle cue to the page. Not an `#[AsTool]` — the LLM never calls this;
     * {@see \App\MessageHandler\RunAgentTurnHandler} brackets the tool loop with `start`/`end` so the
     * dashboard's progress heartbeat knows the worker is running and when it settles. The HTTP 202
     * ack can no longer carry that signal now the turn runs out of the request thread.
     */
    public function signalTurnPhase(string $phase, bool $failed = false): void
    {
        Assert::inArray($phase, ['start', 'end']);

        if ('start' === $phase) {
            $this->pendingToast = null;
            $this->publish(['type' => 'turn', 'phase' => $phase]);

            return;
        }

        if (null !== $this->pendingToast) {
            $this->publish($this->pendingToast);
            $this->pendingToast = null;
        }

        $this->publish(['type' => 'turn', 'phase' => $phase] + ($failed ? ['outcome' => 'failed'] : []));
    }

    /**
     * Streams a transient progress sentence to the page's heartbeat line. Not an `#[AsTool]` —
     * the LLM never calls this; it is emitted deterministically as the tool-call loop runs (see
     * {@see StepNarrator}, wired into {@see \App\Agent\Toolbox\CompositeToolbox}), so the user sees
     * "Je consulte le catalogue…" while the agent works, without the model spending a tool call on it.
     * A distinct `status` frame (not a `toast`) so it feeds only the heartbeat, never a toast popup.
     *
     * `$tool` rides along so the dashboard can key its step chip on the tool IDENTITY rather than on
     * the wording of the sentence: it used to keyword-match the French prose to recover exactly the
     * key we already hold here. Null for a pilot's own `narrate_step`, which has no tool to name —
     * the field is then omitted rather than sent empty.
     */
    public function narrate(string $message, ?string $tool = null): void
    {
        $this->publish(['type' => 'status', 'message' => $message, ...(null !== $tool ? ['tool' => $tool] : [])]);
    }

    public function toast(string $message, string $tone = 'info'): string
    {
        Assert::inArray($tone, ['info', 'ok', 'warn', 'alarm']);
        $toast = ['type' => 'toast', 'tone' => $tone, 'message' => SpokenSummary::fit($message, SpokenSummary::TOAST_MAX)];
        if (null !== $this->channel->sessionId()) {
            $this->pendingToast = $toast;
        } else {
            $this->publish($toast);
        }

        return 'ok';
    }

    /**
     * Publishes a frame the finder's render tools built on the bound session, through the same
     * best-effort path.
     *
     * @param array<string, mixed> $frame
     */
    public function publishFrame(array $frame): void
    {
        $this->publish($frame);
    }

    /**
     * @param array<string, mixed> $action a typed UIAction
     */
    private function publish(array $action): void
    {
        $topic = $this->channel->topic();
        Assert::notNull($topic, 'No session topic bound for this turn.');

        // A render intent is best-effort UI:
        // a hub that is down (or untrusted TLS) must never turn the tool call into an error the
        // model then sees and tries to "recover" from. Log and move on — the tool still acks ok.
        try {
            $this->hub->publish(new Update($topic, json_encode($action, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for render intent {type} on {topic}: {error}', [
                'type' => $action['type'] ?? '',
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
