<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\TurnCancellations;
use App\Agent\Ui\TurnSessionTopic;
use App\Security\OwnerContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stops a running agent turn from the UI. The turn executes in the messenger worker, decoupled from
 * this request, so cancelling is cooperative: this endpoint only raises a {@see TurnCancellations}
 * flag keyed by (owner, session), {@see TurnSessionTopic::key()}, and
 * {@see \App\Agent\Ui\CancelTurnListener} — running inside the worker — honours it at the next tool-call
 * boundary. Returns 202 (accepted, not "already stopped"): the stop is best-effort and may land a
 * round-trip later, or never if the turn already finished.
 *
 * Under the same `^/agent/turn` firewall as {@see AgentTurnController}, so the caller is authenticated.
 */
final readonly class AgentTurnCancelController
{
    public function __construct(
        private TurnCancellations $cancellations,
        private TurnSessionTopic $sessions,
        private OwnerContext $owner,
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

        if (!TurnSessionTopic::isValidId($sessionId)) {
            return new JsonResponse(['error' => 'sessionId must be 1 to 64 letters, digits, "-" or "_"'], Response::HTTP_BAD_REQUEST);
        }

        // Keyed like the turn's topic, so a caller can only ever stop a turn of their own.
        $this->cancellations->request($this->sessions->key($this->owner->require(), $sessionId));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
