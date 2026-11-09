<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\DecodeBuildCodeInput;
use App\Garage\ToolResult;
use App\Knowledge\BuildCode\BuildCodeDecoders;
use App\Knowledge\BuildCode\InvalidBuildCode;
use App\Knowledge\MakeName;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<DecodeBuildCodeInput, CallToolResult>
 */
final class DecodeBuildCodeProcessor implements ProcessorInterface
{
    public function __construct(private readonly BuildCodeDecoders $decoders)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $code = trim((string) $data->code);
        $date = trim((string) $data->date);

        if ('' === $code && '' === $date) {
            return self::invalid('Provide a build code, or a date (YYYY-MM-DD) for the reverse lookup.');
        }

        $make = MakeName::normalize($data->make);
        if ('' === $make) {
            return self::invalid('Provide the vehicle make (e.g. "Peugeot").');
        }

        $decoder = $this->decoders->forMake($make);
        if (null === $decoder) {
            return ToolResult::error('unsupported_make', \sprintf('No build-code decoder for %s yet (only PSA: Peugeot, Citroën, DS). Do not guess a date.', $make));
        }

        try {
            $decoded = '' !== $code
                ? $decoder->decode($code)
                : $decoder->fromDate($this->parseDate($date));
        } catch (InvalidBuildCode $e) {
            return self::invalid($e->getMessage());
        }

        return ToolResult::of(['status' => 'decoded', 'make' => $make] + $decoded->toArray());
    }

    private function parseDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidBuildCode(\sprintf('"%s" is not a valid date; use YYYY-MM-DD.', $date));
        }

        return $parsed;
    }

    private static function invalid(string $reason): CallToolResult
    {
        return ToolResult::error('invalid_input', $reason);
    }
}
