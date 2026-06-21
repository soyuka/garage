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
            owner: 'test-user',
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

    public function testInlinesVehicleIdentitySoToolsPinTheVariantWithoutARoundTrip(): void
    {
        $vehicle = new Vehicle(
            'Mazda', '6', VehicleType::Car, 'siv',
            year: 2007, modification: '2.0 MZR-CD', engineCode: 'RF5C',
        );

        $builder = new TurnContextBuilder(
            $this->resolver($vehicle),
            new VehicleFactsProjector(),
            $this->repository([]),
        );

        $context = $builder->build(new RunAgentTurn(
            instruction: 'quelle viscosité pour la mazda ?',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            selectedId: '/garage/vehicles/'.$vehicle->getId(),
        ));

        // Identity must be inline so get_motul_oil / web_search pin the exact variant
        // without first calling list_vehicles to learn which Mazda this is.
        self::assertStringContainsString('Mazda', $context);
        self::assertStringContainsString('6', $context);
        self::assertStringContainsString('2007', $context);
        self::assertStringContainsString('2.0 MZR-CD', $context);
        self::assertStringContainsString('RF5C', $context);
    }

    public function testInjectsPendingCtaContextSoTerseRepliesLandInContext(): void
    {
        $builder = new TurnContextBuilder(
            $this->createStub(GarageResolver::class),
            new VehicleFactsProjector(),
            $this->createStub(PlannedMaintenanceRepository::class),
        );

        $context = $builder->build(new RunAgentTurn(
            instruction: 'la 2.0 16V',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            pendingContext: 'Reco huile — quel moteur ? Options : 406 1.8, 406 2.0 16V, 406 2.2',
        ));

        // The card the user is answering must be in context so a terse reply ("la 2.0 16V") is
        // interpreted against it rather than re-routed from scratch.
        self::assertStringContainsString('406 2.0 16V', $context);
        self::assertStringContainsString('la 2.0 16V', $context);
    }

    public function testKeepsContextLeanWhenNoVehicleIsSelected(): void
    {
        $resolver = $this->createMock(GarageResolver::class);
        $resolver->expects(self::never())->method('resolveById');

        $builder = new TurnContextBuilder($resolver, new VehicleFactsProjector(), $this->createStub(PlannedMaintenanceRepository::class));

        $context = $builder->build(new RunAgentTurn('bonjour', 'https://localhost/agent/sessions/abc', 'test-user'));

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
