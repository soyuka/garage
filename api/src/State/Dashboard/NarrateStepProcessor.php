<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\NarrateStepInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `narrate_step`: the heartbeat line + a step chip while the pilot works. In an in-app turn the
 * same frame is emitted deterministically per tool call by {@see \App\Agent\Ui\StepNarrator}; a pilot
 * has no such loop to hook, so it narrates its own steps.
 *
 * The frame doubles as the turn's start — the dashboard's reducer lifts the orb on a status frame
 * that arrives at rest — so a pilot run needs no explicit begin, only {@see SettleDashboardProcessor}
 * to close it (the dashboard's 90s watchdog settles a forgotten one).
 *
 * @implements ProcessorInterface<NarrateStepInput, CallToolResult>
 */
final readonly class NarrateStepProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if ('' === trim($data->message)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'message must not be empty.']);
        }

        $this->render->narrate($data->message);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'status']);
    }
}
