<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\TurnCancellations;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stops a running agent turn from the UI. The turn executes in the messenger worker, decoupled from
 * this request, so cancelling is cooperative: this endpoint only raises a {@see TurnCancellations}
 * flag keyed by the session, and {@see \App\Agent\Ui\CancelTurnListener} — running inside the worker
 * — honours it at the next tool-call boundary. Returns 202 (accepted, not "already stopped"): the
 * stop is best-effort and may land a round-trip later, or never if the turn already finished.
 *
 * Under the same `^/agent/turn` firewall as {@see AgentTurnController}, so the caller is authenticated.
 */
final class AgentTurnCancelController
{
    public function __construct(private readonly TurnCancellations $cancellations)
    {
    }

    #[Route('/agent/turn/cancel', name: 'agent_turn_cancel', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $sessionId = trim((string) (\is_array($payload) ? ($payload['sessionId'] ?? '') : ''));

        if ('' === $sessionId) {
            return new JsonResponse(['error' => 'sessionId is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->cancellations->request($sessionId);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
