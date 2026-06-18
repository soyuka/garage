<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\RunAgentTurn;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enqueues one agent turn and acks. The turn itself runs in a `messenger:consume` worker (see
 * {@see \App\MessageHandler\RunAgentTurnHandler}) — never in this request thread, because the
 * agent's multi-round-trip tool loop (persist → catalog → web_search → plan_maintenance → render
 * intents) routinely outruns the request's max_execution_time and was being killed mid-turn with a
 * MaxExecutionTimeError, leaving the work half-done.
 *
 * The HTTP contract stays a bare 202 ack: there is no chat transcript and no action list in the
 * body. The dashboard updates progressively from the render intents the worker streams to the
 * caller's per-session Mercure topic; domain writes fan out on public vehicle topics for free.
 */
final class AgentTurnController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/agent/turn', name: 'agent_turn', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = \is_array($payload) ? $payload : [];

        $instruction = trim((string) ($payload['instruction'] ?? ''));
        $sessionId = trim((string) ($payload['sessionId'] ?? ''));

        if ('' === $instruction || '' === $sessionId) {
            return new JsonResponse(['error' => 'instruction and sessionId are required'], Response::HTTP_BAD_REQUEST);
        }

        $this->bus->dispatch(new RunAgentTurn(
            instruction: $instruction,
            sessionTopic: $request->getSchemeAndHttpHost().'/agent/sessions/'.$sessionId,
            selectedId: trim((string) ($payload['selectedId'] ?? '')),
            summary: trim((string) ($payload['summary'] ?? '')),
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
