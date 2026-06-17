<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Ui\RenderIntentTools;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One agent turn. The user instruction drives the Symfony AI garage agent: it reads/writes the
 * garage over the in-process MCP toolbox and emits render intents through the render-intent tools.
 * Those intents are published to the caller's per-session Mercure topic *as the loop produces
 * them*, so the dashboard updates progressively; domain writes fan out on public vehicle topics
 * for free. The HTTP response is a bare 202 ack — there is no chat transcript and no action list
 * in the body (that was the ui.md step-3 stub; the contract is now "intents arrive over Mercure").
 */
final class AgentTurnController
{
    public function __construct(
        private readonly AgentInterface $garage,
        private readonly RenderIntentTools $renderTools,
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

        $sessionTopic = $request->getSchemeAndHttpHost().'/agent/sessions/'.$sessionId;
        $this->renderTools->bindSession($sessionTopic);

        $this->garage->call(new MessageBag(Message::ofUser($this->turnContext($instruction, $payload))));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    /**
     * The per-turn context handed to the LLM: the instruction plus a compact snapshot of what the
     * dashboard is currently showing (selected vehicle id/summary). Tool results stay server-side
     * and capped; only ids/summaries enter the model. The durable contract (tool catalog, dedup
     * rules, UI-intent vocabulary) lives in the agent's configured system prompt (ai.yaml).
     *
     * @param array<string, mixed> $payload
     */
    private function turnContext(string $instruction, array $payload): string
    {
        $context = "Instruction de l'utilisateur : ".$instruction;

        if ('' !== ($selected = trim((string) ($payload['selectedId'] ?? '')))) {
            $context .= "\n\nVéhicule actuellement sélectionné dans le tableau de bord : ".$selected;
        }

        if ('' !== ($summary = trim((string) ($payload['summary'] ?? '')))) {
            $context .= "\nRésumé : ".$summary;
        }

        return $context;
    }
}
