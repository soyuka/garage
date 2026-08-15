<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\FocusViewInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `focus_view`: bring one panel to the centre of the workshop and light the matching areas of
 * the car.
 *
 * @implements ProcessorInterface<FocusViewInput, CallToolResult>
 */
final readonly class FocusViewProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (!\in_array($data->panel, ['tasks', 'specs', 'carnet'], true)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'panel must be "tasks", "specs" or "carnet".']);
        }

        $keys = array_values(array_filter(array_map(trim(...), $data->keys), static fn (string $k): bool => '' !== $k));
        $this->render->focusView($data->panel, $keys);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'focusView']);
    }
}
