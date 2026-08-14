<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\SettleDashboardInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `settle_dashboard`: closes the pilot run — the orb plays its done filament and returns to
 * veille. The optional toast is published BEFORE the settle so the filament takes its colour from
 * that tone (the reducer derives the result from the worst toast of the turn), exactly like the
 * in-app turn's closing toast + end_turn pair.
 *
 * @implements ProcessorInterface<SettleDashboardInput, CallToolResult>
 */
final readonly class SettleDashboardProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (!\in_array($data->tone, ['info', 'ok', 'warn', 'alarm'], true)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'tone must be one of: info, ok, warn, alarm.']);
        }

        if (null !== $data->message && '' !== trim($data->message)) {
            $this->render->toast($data->message, $data->tone);
        }

        $this->render->signalTurnPhase('end');

        return ToolResult::of(['status' => 'rendered', 'intent' => 'turn:end']);
    }
}
