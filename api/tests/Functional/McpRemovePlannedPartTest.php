<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

final class McpRemovePlannedPartTest extends AppTestCase
{
    use McpClientTrait;

    public function testRemovePlannedPartToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->listTools($client, $sessionId) as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('remove_planned_part', $tools);
        $this->assertSame(['task', 'part'], $tools['remove_planned_part']['inputSchema']['required']);
        $this->assertStringContainsString('dismiss_maintenance', $tools['remove_planned_part']['description']);
    }

    public function testRemovesExactlyTheNamedLine(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $plan = $this->plan($client, $sessionId);
        $parts = $plan['task']['parts'];
        $this->assertCount(3, $parts);

        $result = $this->remove($client, $sessionId, $plan['task']['@id'], $parts[1]['id']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($plan['task']['@id'], $result['task']['@id']);
        $this->assertSame([$parts[0]['id'], $parts[2]['id']], array_column($result['task']['parts'], 'id'));
        $this->assertNotContains($parts[1]['ref'], array_column($result['task']['parts'], 'ref'));

        $again = $this->remove($client, $sessionId, $plan['task']['@id'], $parts[1]['id']);
        $this->assertSame('not_found', $again['error']);
    }

    public function testUnknownPartOrTaskIsNotFound(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $plan = $this->plan($client, $sessionId);

        $this->assertSame('not_found', $this->remove($client, $sessionId, $plan['task']['@id'], '01JZZZZZZZZZZZZZZZZZZZZZZZ')['error']);
        $this->assertSame('not_found', $this->remove($client, $sessionId, $plan['task']['@id'], 'not-a-ulid')['error']);
        $this->assertSame('not_found', $this->remove($client, $sessionId, '/garage/planned-tasks/01JZZZZZZZZZZZZZZZZZZZZZZZ', $plan['task']['parts'][0]['id'])['error']);
        $this->assertSame('not_found', $this->remove($client, $sessionId, '/garage/planned-tasks/garbage', $plan['task']['parts'][0]['id'])['error']);

        $listed = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'list_planned_maintenance', 'arguments' => ['vehicle' => $plan['task']['vehicle']]], 8));
        $this->assertCount(3, $listed['tasks'][0]['parts']);
    }

    public function testAnotherOwnersTaskIsNotFoundAndUntouched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner('someone-else@example.com');
        $task = new PlannedMaintenance($vehicle, 'oil_change', 'user', parts: [['ref' => 'W 712/75']]);
        $dm->persist($vehicle);
        $dm->persist($task);
        $dm->flush();
        $partId = $task->getParts()[0]['id'];
        $taskId = (string) $task->getId();
        $dm->clear();

        $result = $this->remove($client, $this->initialize($client), '/garage/planned-tasks/'.$taskId, $partId);

        $this->assertSame('not_found', $result['error']);
        $this->assertCount(1, $dm->find(PlannedMaintenance::class, $taskId)->getParts());
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Client $client, string $sessionId): array
    {
        return $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'oil_change',
                'parts' => [
                    ['ref' => 'W 712/75', 'label' => 'Filtre à huile'],
                    ['ref' => 'OEM-DRAIN-GASKET', 'label' => 'Joint de bouchon'],
                    ['ref' => 'SAE 5W-30', 'label' => 'Huile moteur'],
                ],
            ],
        ], 3));
    }

    /**
     * @return array<string, mixed>
     */
    private function remove(Client $client, string $sessionId, string $task, string $part): array
    {
        return $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'remove_planned_part',
            'arguments' => ['task' => $task, 'part' => $part],
        ], 9));
    }
}
