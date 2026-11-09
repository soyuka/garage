<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * A render intent carries model-supplied handles the dashboard then acts on with the operator's
 * bearer (it PATCHes a CTA's task, it selects a vehicle). Only a handle to one of the caller's own
 * vehicles or tasks, in its canonical relative form, may reach a frame.
 */
final class RenderTargetOwnershipTest extends AppTestCase
{
    use McpClientTrait;
    use PilotFrameTrait;

    private const string INTRUDER = 'intruder@example.com';

    public function testACtaOnAnOwnedTaskCarriesItsCanonicalIri(): void
    {
        [, $task] = $this->seed(self::TEST_OWNER);

        foreach ([(string) $task->getId(), '/garage/planned-tasks/'.$task->getId()] as $handle) {
            $outcome = $this->proposeOn($handle);

            $this->assertSame('rendered', $outcome['status'] ?? null);
            $this->assertSame('/garage/planned-tasks/'.$task->getId(), $this->lastPilotFrame()['actions'][0]['patchTask']['id'] ?? null);
        }
    }

    public function testACtaOnAnotherOwnersTaskIsRefusedAndNothingIsPublished(): void
    {
        [, $task] = $this->seed(self::INTRUDER);

        $outcome = $this->proposeOn((string) $task->getId());

        $this->assertSame('not_found', $outcome['error'] ?? null);
        $this->assertNull($this->lastPilotFrame());
    }

    public function testACtaWithAForeignOrMalformedTaskHandleIsRefused(): void
    {
        [, $task] = $this->seed(self::TEST_OWNER);

        foreach (['https://evil.example/garage/planned-tasks/'.$task->getId(), '//evil.example/x', '/garage/vehicles/'.$task->getId(), '01JTASK', '/garage/planned-tasks/'.$task->getId().'/../x'] as $handle) {
            $outcome = $this->proposeOn($handle);

            $this->assertSame('invalid_input', $outcome['error'] ?? null, $handle);
            $this->assertNull($this->lastPilotFrame(), $handle);
        }
    }

    public function testSelectVehicleOnlyPublishesAnOwnedVehicle(): void
    {
        [$own] = $this->seed(self::TEST_OWNER);
        [$foreign] = $this->seed(self::INTRUDER);

        $this->assertSame('rendered', $this->select('/garage/vehicles/'.$own->getId())['status'] ?? null);
        $this->assertSame(['type' => 'selectVehicle', 'id' => '/garage/vehicles/'.$own->getId()], $this->lastPilotFrame());

        $this->assertSame('not_found', $this->select('/garage/vehicles/'.$foreign->getId())['error'] ?? null);
        $this->assertSame('invalid_input', $this->select('https://evil.example/garage/vehicles/'.$own->getId())['error'] ?? null);
        $this->assertSame('invalid_input', $this->select('/garage/planned-tasks/'.$own->getId())['error'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposeOn(string $taskHandle): array
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        return $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Filtre à huile',
                'actions' => [['label' => 'Ajouter', 'taskId' => $taskHandle, 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]]],
            ],
        ], 3));
    }

    /**
     * @return array<string, mixed>
     */
    private function select(string $vehicle): array
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        return $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'select_vehicle',
            'arguments' => ['vehicle' => $vehicle],
        ], 3));
    }

    /**
     * @return array{Vehicle, PlannedMaintenance}
     */
    private function seed(string $owner): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'ESPACE', VehicleType::Car, 'test');
        $vehicle->setOwner($owner);
        $task = new PlannedMaintenance($vehicle, 'oil_change', 'test');
        $dm->persist($vehicle);
        $dm->persist($task);
        $dm->flush();
        $dm->clear();

        return [$vehicle, $task];
    }
}
