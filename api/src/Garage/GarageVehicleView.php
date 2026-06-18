<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;

/**
 * Single source of truth for the dereferenceable vehicle item shape — its scalars plus the inlined
 * facts. {@see \App\State\GarageVehicleProvider} uses it to fill the GET item, and
 * {@see \App\Mercure\DomainPublisher} uses {@see self::toArray()} to push the same shape over
 * Mercure on a write, so the dashboard renders a fresh vehicle without a second request. The open
 * checklist is not part of this shape — it is its own subresource
 * (GET /garage/vehicles/{id}/planned-tasks), which the dashboard fetches on select.
 */
final class GarageVehicleView
{
    public function __construct(
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    /**
     * The vehicle's facts with provenance — the shape the item GET inlines (keyed `id`/`key`, not
     * the MCP FactView's `@id`).
     *
     * @return list<array{key: string, value: string, unit: string|null, source: string, verified: bool, addedAt: string, url: string|null}>
     */
    public function factsOf(Vehicle $vehicle): array
    {
        // Facts are a key-indexed embedded map; array_values drops the keys so it serializes as a
        // JSON array (the dashboard reads `facts` as a list and keys each row by `key`).
        return array_map(static fn (VehicleFact $f): array => [
            'key' => $f->getKey(),
            'value' => $f->getValue(),
            'unit' => $f->getUnit(),
            'source' => $f->getSource(),
            'verified' => $f->isVerified(),
            'addedAt' => $f->getAddedAt()->format(\DateTimeInterface::ATOM),
            'url' => $f->getUrl(),
        ], array_values($vehicle->getFacts()->toArray()));
    }

    /**
     * The full item read-model as a plain array — the same fields the GET serializes (plus `@id`),
     * for delivery over Mercure.
     *
     * @return array<string, mixed>
     */
    public function toArray(Vehicle $vehicle): array
    {
        return [
            '@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle)),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
            'source' => $vehicle->getSource(),
            'kType' => $vehicle->getKType(),
            'plate' => $vehicle->getPlate(),
            'vin' => $vehicle->getVin(),
            'usageValue' => $vehicle->getUsageValue(),
            'usageUnit' => $vehicle->getUsageUnit()?->value,
            'factCount' => $vehicle->getFacts()->count(),
            'facts' => $this->factsOf($vehicle),
            'addedAt' => $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
