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
 * MCP `narrate_step`: the pilot's own heartbeat line while it works — the only sentence the ticker
 * shows. A pilot's tool calls raise no step frame of their own (no in-app tool loop to hook).
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
            return ToolResult::error('invalid_input', 'message must not be empty.');
        }

        $this->render->narrate($data->message);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'status']);
    }
}
