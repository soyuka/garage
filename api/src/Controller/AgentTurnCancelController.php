<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\TurnCancellations;
use App\Agent\Ui\DashboardChannel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stops a running agent turn from the UI. The turn executes in the messenger worker, decoupled from
 * this request, so cancelling is cooperative: this endpoint only raises a {@see TurnCancellations}
 * flag keyed by (owner, session) — {@see DashboardChannel::sessionKey()} — and {@see \App\Agent\Ui\CancelTurnListener} — running inside the worker
 * — honours it at the next tool-call boundary. Returns 202 (accepted, not "already stopped"): the
 * stop is best-effort and may land a round-trip later, or never if the turn already finished.
 *
 * Under the same `^/agent/turn` firewall as {@see AgentTurnController}, so the caller is authenticated.
 */
final class AgentTurnCancelController
{
    public function __construct(
        private readonly TurnCancellations $cancellations,
        private readonly DashboardChannel $channel,
        private readonly Security $security,
    ) {
    }

    #[Route('/agent/turn/cancel', name: 'agent_turn_cancel', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $sessionId = trim((string) (\is_array($payload) ? ($payload['sessionId'] ?? '') : ''));

        if ('' === $sessionId) {
            return new JsonResponse(['error' => 'sessionId is required'], Response::HTTP_BAD_REQUEST);
        }

        if (1 !== preg_match(DashboardChannel::SESSION_ID_PATTERN, $sessionId)) {
            return new JsonResponse(['error' => 'sessionId must be 1-128 of [A-Za-z0-9_-]'], Response::HTTP_BAD_REQUEST);
        }

        // Keyed like the turn's topic, so a caller can only ever stop a turn of their own.
        $this->cancellations->request($this->channel->sessionKey($this->security->getUser()?->getUserIdentifier() ?? '', $sessionId));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
