<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;

final class AgentTurnSelectedVehicleScopingTest extends AppTestCase
{
    public function testAForeignSelectedVehicleNeverReachesTheContext(): void
    {
        $this->assertContextOmits($this->seedVehicle('stranger@example.com', 'FOREIGNPLATE'), 'FOREIGNPLATE');
    }

    public function testADeletedSelectedVehicleNeverReachesTheContext(): void
    {
        $this->assertContextOmits($this->seedVehicle(self::TEST_OWNER, 'DELETEDPLATE', deleted: true), 'DELETEDPLATE');
    }

    public function testTheOwnersSelectedVehicleIsInlined(): void
    {
        $vehicle = $this->seedVehicle(self::TEST_OWNER, 'MYPLATE');

        $this->assertStringContainsString('plaque MYPLATE', $this->turnContext($vehicle));
    }

    private function assertContextOmits(Vehicle $vehicle, string $plate): void
    {
        $context = $this->turnContext($vehicle);

        $this->assertStringNotContainsString($plate, $context);
        $this->assertStringNotContainsString('PEUGEOT', $context);
    }

    private function turnContext(Vehicle $vehicle): string
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'Fait.'));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Bonjour', 'sessionId' => 'sess-scope', 'selectedId' => '/garage/vehicles/'.(string) $vehicle->getId()],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $turns = static::getContainer()->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        $this->assertCount(1, $turns);

        return $turns[0]->getContext();
    }

    private function seedVehicle(string $owner, string $plate, bool $deleted = false): Vehicle
    {
        static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('PEUGEOT', '308', VehicleType::Car, 'user', year: 2015, plate: $plate);
        $vehicle->setOwner($owner);
        if ($deleted) {
            $vehicle->delete();
        }
        $dm->persist($vehicle);
        $dm->flush();

        return $vehicle;
    }
}
