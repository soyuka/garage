<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\ShowFactInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `show_fact`: raise the focused spec panel for the SELECTED vehicle — call select_vehicle first
 * when the answer is about another one.
 *
 * @implements ProcessorInterface<ShowFactInput, CallToolResult>
 */
final readonly class ShowFactProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $keys = array_values(array_filter(array_map(trim(...), $data->keys), static fn (string $k): bool => '' !== $k));
        if ([] === $keys) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'keys must hold at least one vehicle-fact key, e.g. ["oil_capacity"].']);
        }

        $this->render->showFact($keys, $data->title);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'showFact']);
    }
}
