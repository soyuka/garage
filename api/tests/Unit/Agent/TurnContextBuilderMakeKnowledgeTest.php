<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\TurnContextBuilder;
use App\Entity\MakeKnowledge;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\Garage\VehicleType;
use App\Maintenance\VehicleFactsProjector;
use App\Message\RunAgentTurn;
use App\Repository\MakeKnowledgeRepository;
use App\Repository\PlannedMaintenanceRepository;
use PHPUnit\Framework\TestCase;

final class TurnContextBuilderMakeKnowledgeTest extends TestCase
{
    /**
     * @param list<MakeKnowledge> $entries
     */
    private function build(Vehicle $vehicle, array $entries, bool $withRepository = true): string
    {
        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);
        $tasks = $this->createStub(PlannedMaintenanceRepository::class);
        $tasks->method('checklistForVehicle')->willReturn([]);
        $knowledge = $this->createStub(MakeKnowledgeRepository::class);
        $knowledge->method('findForMake')->willReturn($entries);

        $builder = new TurnContextBuilder($resolver, new VehicleFactsProjector(), $tasks, $withRepository ? $knowledge : null);

        return $builder->build(new RunAgentTurn(
            instruction: 'quelle bougie ?',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'test-user',
            selectedId: '/garage/vehicles/'.(string) $vehicle->getId(),
        ));
    }

    public function testListsTheTopicTitlesOfTheSelectedVehiclesMake(): void
    {
        $vehicle = new Vehicle('Peugeot', '307', VehicleType::Car, 'user');
        $entries = [
            new MakeKnowledge(['PEUGEOT'], 'numero-organisation', 'Numéro d\'organisation (OPR)', 'long corps qui ne doit pas apparaître', [], true),
            new MakeKnowledge(['PEUGEOT'], 'ou-trouver-opr', 'Où trouver le DAM', 'autre corps', [], true),
        ];

        $context = $this->build($vehicle, $entries);

        $this->assertStringContainsString('get_make_knowledge', $context);
        $this->assertStringContainsString('Numéro d\'organisation (OPR)', $context);
        $this->assertStringContainsString('Où trouver le DAM', $context);
        $this->assertStringNotContainsString('long corps', $context);
    }

    public function testAddsNothingWhenTheMakeHasNoKnowledge(): void
    {
        $context = $this->build(new Vehicle('Mazda', '6', VehicleType::Car, 'user'), []);

        $this->assertStringNotContainsString('get_make_knowledge', $context);
    }

    public function testStillWorksWithoutAKnowledgeRepository(): void
    {
        $context = $this->build(new Vehicle('Mazda', '6', VehicleType::Car, 'user'), [], withRepository: false);

        $this->assertStringContainsString('Mazda', $context);
        $this->assertStringNotContainsString('get_make_knowledge', $context);
    }
}
