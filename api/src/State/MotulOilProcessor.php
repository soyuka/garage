<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\MotulOilInput;
use App\Exception\UpstreamException;
use App\Garage\ToolResult;
use App\Motul\MotulOilAdvisor;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_motul_oil` tool. An upstream failure (Motul down / format shift) is returned as a
 * structured result, never thrown, so it reaches the model as recoverable text instead of a
 * -32603 protocol error that would abort the turn.
 *
 * @implements ProcessorInterface<MotulOilInput, CallToolResult>
 */
final class MotulOilProcessor implements ProcessorInterface
{
    public function __construct(private readonly MotulOilAdvisor $advisor)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $search = trim($data->search);
        if ('' === $search) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'Provide a search, e.g. "Mazda 6".']);
        }

        try {
            return ToolResult::of($this->advisor->advise($search, $data->year, $data->engine));
        } catch (UpstreamException $e) {
            return ToolResult::of([
                'status' => 'upstream_error',
                'query' => $search,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
