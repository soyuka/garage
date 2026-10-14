<?php

declare(strict_types=1);

namespace App\Metadata;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use App\Dto\EditMaintenanceInput;
use App\Dto\LogMaintenanceInput;
use App\Dto\MaintenanceRecordInput;
use App\Dto\PlanMaintenanceInput;
use App\Maintenance\ConsumableStandard;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator('api_platform.metadata.property.metadata_factory')]
final class ConsumableStandardPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    public function __construct(private readonly PropertyMetadataFactoryInterface $decorated)
    {
    }

    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->decorated->create($resourceClass, $property, $options);
        $schema = $metadata->getSchema();
        if (null === $schema) {
            return $metadata;
        }

        if (PlanMaintenanceInput::class === $resourceClass && 'parts' === $property) {
            $schema['description'] = $this->append($schema['description'] ?? '');

            return $metadata->withSchema($schema);
        }

        if ('items' === $property && \in_array($resourceClass, [LogMaintenanceInput::class, EditMaintenanceInput::class, MaintenanceRecordInput::class], true)) {
            $parts = $schema['items']['properties']['parts'] ?? null;
            if (null !== $parts) {
                $schema['items']['properties']['parts']['description'] = $this->append($parts['description'] ?? '');

                return $metadata->withSchema($schema);
            }
        }

        return $metadata;
    }

    private function append(string $description): string
    {
        return trim($description.' '.ConsumableStandard::describe());
    }
}
