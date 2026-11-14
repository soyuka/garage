<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves WHERE a render frame is published: the per-tab session topic
 * (`…/agent/sessions/<uuid>`) the turn bound before its loop ran, so only the tab that asked reacts.
 * Nowhere when no turn is bound.
 */
final class DashboardChannel implements ResetInterface
{
    private ?string $sessionTopic = null;

    public function bindSession(?string $sessionTopic): void
    {
        $this->sessionTopic = $sessionTopic;
    }

    /**
     * The bound turn session's id — the last segment of `…/agent/sessions/<id>`. It is the key the
     * cancel flag is stored under, so {@see CancelTurnListener} can tell whether THIS turn was asked
     * to stop.
     */
    public function reset(): void
    {
        $this->sessionTopic = null;
    }

    public function sessionId(): ?string
    {
        if (null === $this->sessionTopic) {
            return null;
        }

        return substr($this->sessionTopic, (int) strrpos($this->sessionTopic, '/') + 1);
    }

    public function topic(): ?string
    {
        return $this->sessionTopic;
    }
}
