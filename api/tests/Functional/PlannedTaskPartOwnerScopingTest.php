<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

final class PlannedTaskPartOwnerScopingTest extends AppTestCase
{
    /**
     * @return array{taskId: string, partId: string}
     */
    private function seedForeignTask(): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner('someone-else@example.com');
        $task = new PlannedMaintenance($vehicle, 'oil_change', 'user', parts: [['ref' => 'W 712/75', 'qty' => 1]]);
        $dm->persist($vehicle);
        $dm->persist($task);
        $dm->flush();
        $ids = ['taskId' => (string) $task->getId(), 'partId' => $task->getParts()[0]['id']];
        $dm->clear();

        return $ids;
    }

    public function testForeignTaskPartCannotBeAdded(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['taskId' => $taskId] = $this->seedForeignTask();

        $client->request('POST', '/garage/planned-tasks/'.$taskId.'/parts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['ref' => 'F 026 407 085'],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertCount(1, static::getContainer()->get(DocumentManager::class)->find(PlannedMaintenance::class, $taskId)->getParts());
    }

    public function testForeignTaskPartCannotBePatched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['taskId' => $taskId, 'partId' => $partId] = $this->seedForeignTask();

        $client->request('PATCH', '/garage/planned-tasks/'.$taskId.'/parts/'.$partId, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['qty' => 9],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(1, static::getContainer()->get(DocumentManager::class)->find(PlannedMaintenance::class, $taskId)->getParts()[0]['qty']);
    }

    public function testForeignTaskPartCannotBeDeleted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['taskId' => $taskId, 'partId' => $partId] = $this->seedForeignTask();

        $client->request('DELETE', '/garage/planned-tasks/'.$taskId.'/parts/'.$partId, ['headers' => self::authHeaders()]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertCount(1, static::getContainer()->get(DocumentManager::class)->find(PlannedMaintenance::class, $taskId)->getParts());
    }

    public function testForeignTaskCannotBeRead(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['taskId' => $taskId] = $this->seedForeignTask();

        $client->request('GET', '/garage/planned-tasks/'.$taskId, ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testForeignTaskCannotBePatched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['taskId' => $taskId] = $this->seedForeignTask();

        $client->request('PATCH', '/garage/planned-tasks/'.$taskId, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['label' => 'hijacked'],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertNull(static::getContainer()->get(DocumentManager::class)->find(PlannedMaintenance::class, $taskId)->getLabel());
    }
}
