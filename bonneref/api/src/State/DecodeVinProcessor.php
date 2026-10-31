<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\VinInput;
use App\Mcp\ToolResult;
use App\Vin\VinDecoder;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<VinInput, CallToolResult>
 */
final readonly class DecodeVinProcessor implements ProcessorInterface
{
    public function __construct(private VinDecoder $decoder)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        return ToolResult::of(get_object_vars($this->decoder->decode($data->vin)));
    }
}
