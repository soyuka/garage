<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\RevealAssemblyInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `reveal_assembly`: ghost the car's shell to a wireframe and raise that region's schematic
 * assembly under it.
 *
 * @implements ProcessorInterface<RevealAssemblyInput, CallToolResult>
 */
final readonly class RevealAssemblyProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (!\in_array($data->region, RenderIntentTools::BODY_REGIONS, true)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'hint' => \sprintf('region must be one of: %s.', implode(', ', RenderIntentTools::BODY_REGIONS)),
            ]);
        }

        $keys = array_values(array_filter(array_map(trim(...), $data->partKeys), static fn (string $k): bool => '' !== $k));
        $this->render->revealAssembly($data->region, $keys);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'revealAssembly']);
    }
}
