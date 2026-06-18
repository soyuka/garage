<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\TurnContextBuilder;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Garage\GarageResolver;
use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Maintenance\VehicleFactsProjector;
use App\Message\RunAgentTurn;
use App\Repository\PlannedMaintenanceRepository;
use PHPUnit\Framework\TestCase;

final class TurnContextBuilderTest extends TestCase
{
    public function testInjectsKnownFactsAndOpenTasksForTheSelectedVehicle(): void
    {
        $vehicle = new Vehicle('Mazda', '6', VehicleType::Car, 'siv', year: 2007);
        $task = new PlannedMaintenance(
            $vehicle, 'oil_change', 'user',
            targetUsageValue: 250000, targetUsageUnit: UsageUnit::Kilometers,
        );
        $task->setParts([['ref' => 'W712/52', 'brand' => 'MANN']]);
        $task->setAttribute(VehicleFact::fromSource('oil_capacity', '4.3', 'partsapi', unit: 'L'));

        $builder = new TurnContextBuilder(
            $this->resolver($vehicle),
            new VehicleFactsProjector(),
            $this->repository([$task]),
        );

        $context = $builder->build(new RunAgentTurn(
            instruction: 'ajoute l\'huile et la quantité à la liste',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            selectedId: '/garage/vehicles/'.$vehicle->getId(),
        ));

        // Known fact must be present so the agent reads it instead of web-searching.
        self::assertStringContainsString('oil_capacity', $context);
        self::assertStringContainsString('4.3', $context);
        // Open task must carry its id so the agent can close it via log_maintenance.
        self::assertStringContainsString((string) $task->getId(), $context);
        self::assertStringContainsString('oil_change', $context);
        self::assertStringContainsString('250000', $context);
    }

    public function testKeepsContextLeanWhenNoVehicleIsSelected(): void
    {
        $resolver = $this->createMock(GarageResolver::class);
        $resolver->expects(self::never())->method('resolveById');

        $builder = new TurnContextBuilder($resolver, new VehicleFactsProjector(), $this->createStub(PlannedMaintenanceRepository::class));

        $context = $builder->build(new RunAgentTurn('bonjour', 'https://localhost/agent/sessions/abc'));

        self::assertStringContainsString('bonjour', $context);
        self::assertStringNotContainsString('Faits connus', $context);
    }

    private function resolver(Vehicle $vehicle): GarageResolver
    {
        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);

        return $resolver;
    }

    /**
     * @param list<PlannedMaintenance> $tasks
     */
    private function repository(array $tasks): PlannedMaintenanceRepository
    {
        $repository = $this->createStub(PlannedMaintenanceRepository::class);
        $repository->method('checklistForVehicle')->willReturn($tasks);

        return $repository;
    }
}
