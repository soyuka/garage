<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\SelectVehicleInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `select_vehicle`: the pilot equivalent of the agent's render intent — same frame, published to
 * the owner's pilot topic (see {@see \App\Agent\Ui\DashboardChannel}).
 *
 * @implements ProcessorInterface<SelectVehicleInput, CallToolResult>
 */
final readonly class SelectVehicleProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if ('' === trim($data->vehicle)) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'vehicle must be a vehicle @id, e.g. "/garage/vehicles/01J…".']);
        }

        $this->render->selectVehicle($data->vehicle);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'selectVehicle']);
    }
}
