<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stub agent turn for the headless-UI vertical slice (ui.md step 3). No LLM yet: it returns a
 * fixed UI-action list so the dashboard's action loop can be proven end to end. Step 4 swaps the
 * body for the Symfony AI agent over MCP, emitting domain writes + UI-intents — the response
 * shape ({actions: UIAction[]}) is the contract that stays.
 */
final class AgentTurnController
{
    #[Route('/agent/turn', name: 'agent_turn', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $instruction = \is_array($payload) ? trim((string) ($payload['instruction'] ?? '')) : '';

        $actions = [
            ['type' => 'toast', 'tone' => 'info', 'message' => 'Copilote Atelier : « '.$instruction.' »'],
        ];

        if (str_contains(mb_strtolower($instruction), 'vidange')) {
            $actions[] = ['type' => 'renderSuggestions', 'chips' => [
                ['label' => 'Pièces pour la vidange'],
                ['label' => 'Filtre à huile'],
                ['label' => 'Capacité huile moteur'],
            ]];
            $actions[] = ['type' => 'patchPanel', 'panel' => 'nextService', 'data' => ['value' => 1200, 'unit' => 'km']];
            $actions[] = ['type' => 'toast', 'tone' => 'ok', 'message' => 'Vidange : pièces suggérées ci-dessous.'];
        }

        return new JsonResponse(['actions' => $actions]);
    }
}
