<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\State\VehicleForecastProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The per-vehicle maintenance forecast read-model at /garage/vehicles/{vehicleId}/forecast — the
 * dashboard's "Prévisions d'entretien" section. Two honest halves, mirroring the list_due_maintenance
 * MCP tool but scoped to one vehicle (no garage-wide identity block on each row):
 *  - `due`:  services we can predict (last logged service + the schedule/default interval), each with
 *            its time and/or usage axis, soonest first.
 *  - `gaps`: recurring services with a known cadence but NO logged baseline — next-due unknown, a
 *            prompt to log when each was last done.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/garage/vehicles/{vehicleId}/forecast',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            provider: VehicleForecastProvider::class,
        ),
    ],
)]
final class VehicleForecast
{
    #[ApiProperty(identifier: true, description: 'Public ULID of the vehicle this forecast is for.')]
    public Ulid $vehicleId;

    #[ApiProperty(writable: false, description: 'IRI of the vehicle this forecast is for.')]
    public string $vehicle;

    /**
     * Predicted services, soonest first. Each: {type, action, component, rule (schedule|default),
     * lastPerformedAt, dueAt, projectedDueAt, overdue, daysUntilDue, intervalMonths, usage, usageInterval}.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(writable: false)]
    public array $due = [];

    /**
     * Recurring service types with a known cadence but no logged baseline — next-due unknown.
     *
     * @var list<string>
     */
    #[ApiProperty(writable: false)]
    public array $gaps = [];
}
