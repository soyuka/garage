<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Maintenance\VehicleFactsProjector;
use App\Maintenance\VehicleHealthCalculator;

/**
 * Single source of truth for the dereferenceable vehicle item shape — its scalars plus the inlined
 * facts. {@see \App\State\GarageVehicleProvider} uses it to fill the GET item, and
 * {@see \App\Mercure\DomainPublisher} uses {@see self::toArray()} to push the same shape over
 * Mercure on a write, so the dashboard renders a fresh vehicle without a second request. The open
 * checklist is not part of this shape — it is its own subresource
 * (GET /garage/vehicles/{id}/planned-tasks), which the dashboard fetches on select.
 */
final readonly class GarageVehicleView
{
    public function __construct(
        private IriConverterInterface $iriConverter,
        private VehicleFactsProjector $factsProjector,
        private VehicleHealthCalculator $health,
    ) {
    }

    /**
     * The vehicle's composed maintenance health badge — status + 0–100 score + the breakdown the
     * badge explains itself with. Computed on read from the forecast; the vehicle stores no health.
     *
     * @return array{status: string, score: int|null, overdue: int, dueSoon: int, tracked: int, gaps: int}
     */
    public function healthOf(Vehicle $vehicle): array
    {
        $health = $this->health->forVehicle($vehicle);

        return [
            'status' => $health->status->value,
            'score' => $health->score,
            'overdue' => $health->overdue,
            'dueSoon' => $health->dueSoon,
            'tracked' => $health->tracked,
            'gaps' => $health->gaps,
        ];
    }

    /**
     * The vehicle's facts with provenance — the shape the item GET inlines (keyed `id`/`key`, not
     * the MCP FactView's `@id`). Facts are projected from the vehicle's maintenance items (their
     * single store), not held on the vehicle.
     *
     * @return list<array{key: string, value: string, unit: string|null, source: string, verified: bool, addedAt: string, url: string|null}>
     */
    public function factsOf(Vehicle $vehicle): array
    {
        return array_map(static fn (VehicleFact $f): array => [
            'key' => $f->getKey(),
            'value' => $f->getValue(),
            'unit' => $f->getUnit(),
            'source' => $f->getSource(),
            'verified' => $f->isVerified(),
            'addedAt' => $f->getAddedAt()->format(\DateTimeInterface::ATOM),
            'url' => $f->getUrl(),
        ], $this->factsProjector->forVehicle($vehicle));
    }

    /**
     * The full item read-model as a plain array — the same fields the GET serializes (plus `@id`),
     * for delivery over Mercure.
     *
     * @return array<string, mixed>
     */
    public function toArray(Vehicle $vehicle): array
    {
        $facts = $this->factsOf($vehicle);

        return [
            '@id' => ResourceIri::of($this->iriConverter, GarageVehicle::fromEntity($vehicle)),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'engineVariant' => $vehicle->getEngineVariant(),
            'engineCode' => $vehicle->getEngineCode(),
            'vehicleType' => $vehicle->getVehicleType()->value,
            'source' => $vehicle->getSource(),
            'kType' => $vehicle->getKType(),
            'plate' => $vehicle->getPlate(),
            'vin' => $vehicle->getVin(),
            'usageValue' => $vehicle->getUsageValue(),
            'usageUnit' => $vehicle->getUsageUnit()?->value,
            'factCount' => \count($facts),
            'facts' => $facts,
            'health' => $this->healthOf($vehicle),
            'addedAt' => $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
