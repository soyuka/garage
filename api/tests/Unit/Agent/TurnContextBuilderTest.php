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
            selectedId: '/garage/vehicles/'.(string) $vehicle->getId(),
        ));

        // Known fact must be present so the agent reads it instead of web-searching.
        $this->assertStringContainsString('oil_capacity', $context);
        $this->assertStringContainsString('4.3', $context);
        // Open task must carry its id so the agent can close it via log_maintenance.
        $this->assertStringContainsString((string) $task->getId(), $context);
        $this->assertStringContainsString('oil_change', $context);
        $this->assertStringContainsString('250000', $context);
    }

    public function testInlinesVehicleIdentitySoToolsPinTheVariantWithoutARoundTrip(): void
    {
        $vehicle = new Vehicle(
            'Mazda', '6', VehicleType::Car, 'siv',
            year: 2007, engineVariant: '2.0 MZR-CD', engineCode: 'RF5C',
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
            selectedId: '/garage/vehicles/'.(string) $vehicle->getId(),
        ));

        // Identity must be inline so get_motul_oil / web_search pin the exact variant
        // without first calling list_vehicles to learn which Mazda this is.
        $this->assertStringContainsString('Mazda', $context);
        $this->assertStringContainsString('6', $context);
        $this->assertStringContainsString('2007', $context);
        $this->assertStringContainsString('2.0 MZR-CD', $context);
        $this->assertStringContainsString('RF5C', $context);
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
        $this->assertStringContainsString('406 2.0 16V', $context);
        $this->assertStringContainsString('la 2.0 16V', $context);
    }

    public function testKeepsContextLeanWhenNoVehicleIsSelected(): void
    {
        $resolver = $this->createMock(GarageResolver::class);
        $resolver->expects(self::never())->method('resolveById');

        $builder = new TurnContextBuilder($resolver, new VehicleFactsProjector(), $this->createStub(PlannedMaintenanceRepository::class));

        $context = $builder->build(new RunAgentTurn('bonjour', 'https://localhost/agent/sessions/abc', 'test-user'));

        $this->assertStringContainsString('bonjour', $context);
        $this->assertStringNotContainsString('Faits connus', $context);
    }

    public function testListsEachOpenTaskPartsWithPriceSoTheAgentAnswersFromTheDataset(): void
    {
        $vehicle = new Vehicle('Renault', 'Clio', VehicleType::Car, 'siv', year: 2012);
        $task = new PlannedMaintenance($vehicle, 'clutch', 'user', label: 'Embrayage');
        $task->setParts([
            ['ref' => '623315033', 'brand' => 'LUK', 'qty' => 1, 'label' => 'Kit embrayage (Oscaro 174 €)', 'priceCents' => 17400],
            ['ref' => 'LOCTITE-243', 'label' => 'Frein-filet'],
        ]);

        $builder = new TurnContextBuilder($this->resolver($vehicle), new VehicleFactsProjector(), $this->repository([$task]));
        $context = $builder->build(new RunAgentTurn('combien coûtent les pièces ?', 'https://localhost/agent/sessions/abc', 'test-user', selectedId: '/garage/vehicles/'.(string) $vehicle->getId()));

        $this->assertStringContainsString('      · LUK 623315033 ×1 — Kit embrayage (Oscaro 174 €) [174 €]', $context);
        $this->assertStringContainsString('      · frein-filet moyen — Frein-filet (LOCTITE-243)', $context);
        $this->assertStringNotContainsString('Frein-filet (LOCTITE-243) [', $context);
    }

    public function testInlinesTheKTypeSoTheServiceRecipeSkipsPlateLookup(): void
    {
        $vehicle = new Vehicle('Renault', 'Clio', VehicleType::Car, 'siv', year: 2012);
        $vehicle->setKType(18679);

        $builder = new TurnContextBuilder($this->resolver($vehicle), new VehicleFactsProjector(), $this->repository([]));
        $context = $builder->build(new RunAgentTurn('bonjour', 'https://localhost/agent/sessions/abc', 'test-user', selectedId: '/garage/vehicles/'.(string) $vehicle->getId()));

        $this->assertStringContainsString('kType 18679', $context);
    }

    public function testInlinesTheCarTypeSoAMotorbikeIsQueriedAsMc(): void
    {
        $vehicle = new Vehicle('BMW', 'F 800 GS Adventure', VehicleType::Moto, 'manual', year: 2014);
        $vehicle->setKType(101222);

        $builder = new TurnContextBuilder($this->resolver($vehicle), new VehicleFactsProjector(), $this->repository([]));
        $context = $builder->build(new RunAgentTurn('bonjour', 'https://localhost/agent/sessions/abc', 'test-user', selectedId: '/garage/vehicles/'.(string) $vehicle->getId()));

        $this->assertStringContainsString('kType 101222', $context);
        $this->assertStringContainsString('carType MC', $context);
    }

    private function resolver(?Vehicle $vehicle): GarageResolver
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

    /**
     * The one place the agent is told which language to ANSWER in. It rides the per-turn context
     * rather than the system prompt because the prompt is static config while the locale is per
     * request — and because the prompt's own instructions stay English either way (they are
     * model-facing; only the user-facing output moves).
     */
    public function testTellsTheModelWhichLanguageToAnswerIn(): void
    {
        $builder = new TurnContextBuilder($this->resolver(null), new VehicleFactsProjector(), $this->repository([]));

        $french = $builder->build(new RunAgentTurn(
            instruction: 'what do I need on the Mazda?',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            locale: 'fr',
        ));
        $english = $builder->build(new RunAgentTurn(
            instruction: 'what do I need on the Mazda?',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            locale: 'en',
        ));

        $this->assertStringContainsString('French', $french);
        $this->assertStringContainsString('English', $english);
        $this->assertStringNotContainsString('English', $french);
        $this->assertStringNotContainsString('French', $english);
    }

    public function testAnUnknownLocaleStillGetsADirectiveRatherThanNone(): void
    {
        $builder = new TurnContextBuilder($this->resolver(null), new VehicleFactsProjector(), $this->repository([]));

        $context = $builder->build(new RunAgentTurn(
            instruction: 'x',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            locale: 'de',
        ));

        $this->assertStringContainsString('English', $context);
        $this->assertStringNotContainsString('French', $context);
    }
}
