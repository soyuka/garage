<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\ToastInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `toast`: a transient one-line acknowledgement on the dashboard.
 *
 * @implements ProcessorInterface<ToastInput, CallToolResult>
 */
final readonly class ToastProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if ('' === trim($data->message)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'message must not be empty.']);
        }
        if (!\in_array($data->tone, ['info', 'ok', 'warn', 'alarm'], true)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'tone must be one of: info, ok, warn, alarm.']);
        }

        $this->render->toast($data->message, $data->tone);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'toast']);
    }
}
