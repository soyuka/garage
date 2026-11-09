<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\SetVehicleFactsInput;
use App\Entity\VehicleFact;
use App\Garage\FactView;
use App\Garage\ResourceIri;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Maintenance\VehicleFactWriter;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `set_vehicle_facts` tool: records identity/spec facts on a vehicle addressed by @id, replacing
 * any existing value for the same key. The batch is validated whole before anything is written, and
 * every failure is a structured result, never an exception.
 *
 * @implements ProcessorInterface<SetVehicleFactsInput, CallToolResult>
 */
final readonly class SetVehicleFactsProcessor implements ProcessorInterface
{
    private const int MAX_FACTS = 50;
    private const string KEY_PATTERN = '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/';

    public function __construct(
        private VehicleFactWriter $writer,
        private VehicleIriResolver $iriResolver,
        private IriConverterInterface $iriConverter,
        private FactView $factView,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $facts = $this->parse($data->facts);
        if (\is_string($facts)) {
            return ToolResult::error('invalid_input', $facts);
        }

        $resolved = $this->iriResolver->resolve($data->vehicle);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        $vehicle = $this->writer->vehicleOf($resolved);
        if (null === $vehicle) {
            return $this->iriResolver->notFound($data->vehicle);
        }

        $this->writer->set($vehicle, $facts);

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => ['@id' => ResourceIri::of($this->iriConverter, GarageVehicle::fromEntity($vehicle))],
            'facts' => array_map($this->factView->toArray(...), $facts),
            'shadowed' => $this->writer->shadowed($vehicle, $facts),
        ]);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<VehicleFact>|string the facts, or the reason the batch is invalid
     */
    private function parse(array $rows): array|string
    {
        if ([] === $rows || \count($rows) > self::MAX_FACTS) {
            return \sprintf('facts must hold between 1 and %d entries.', self::MAX_FACTS);
        }

        $facts = [];
        foreach (array_values($rows) as $i => $row) {
            $key = \is_array($row) ? ($row['key'] ?? null) : null;
            $value = \is_array($row) ? ($row['value'] ?? null) : null;
            $source = \is_array($row) ? ($row['source'] ?? null) : null;
            $unit = \is_array($row) ? ($row['unit'] ?? null) : null;
            $url = \is_array($row) ? ($row['url'] ?? null) : null;

            if (!\is_string($key) || 1 !== preg_match(self::KEY_PATTERN, $key) || \strlen($key) > 64) {
                return \sprintf('facts[%d].key must be snake_case (lowercase letters, digits, underscores), got %s.', $i, json_encode($key));
            }
            if (!\is_string($value) || '' === trim($value) || \strlen($value) > 500) {
                return \sprintf('facts[%d].value must be a non-blank string of at most 500 characters.', $i);
            }
            if (!\is_string($source) || !\in_array($source, VehicleFactWriter::WRITABLE_SOURCES, true)) {
                return \sprintf('facts[%d].source must be one of: %s.', $i, implode(', ', VehicleFactWriter::WRITABLE_SOURCES));
            }
            if (null !== $unit && (!\is_string($unit) || '' === trim($unit) || \strlen($unit) > 16)) {
                return \sprintf('facts[%d].unit must be a short non-blank string when given.', $i);
            }
            if (null !== $url && (!\is_string($url) || false === filter_var($url, \FILTER_VALIDATE_URL))) {
                return \sprintf('facts[%d].url must be an absolute URL when given.', $i);
            }

            $facts[] = VehicleFact::fromSource(key: $key, value: trim($value), source: $source, unit: $unit, url: $url);
        }

        return $facts;
    }
}
