<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Vehicle\VehicleIndex;
use Doctrine\ODM\MongoDB\DocumentManager;

final class AgentTurnVehicleNamesTest extends AppTestCase
{
    public function testTheListingFillsMissingNamesFromTheIndexWithoutTouchingTheRow(): void
    {
        $client = static::createClient();
        static::getContainer()->get(VehicleIndex::class)->record(16182, 'PC', 'MAZDA', '6 Hatchback (GG)', '2.0 (GGES)', 'catalog');
        $agent = $this->seed(static fn (AgentTurn $t) => $t->setVehicle(['carId' => 16182, 'carType' => 'PC', 'plate' => null, 'make' => null, 'model' => null]));
        $search = $this->seedSearch(['rule' => 'category', 'carId' => 16182, 'carType' => 'PC', 'plate' => 'AB123CD']);
        $named = $this->seed(static fn (AgentTurn $t) => $t->setVehicle(['carId' => 16182, 'carType' => 'PC', 'plate' => null, 'make' => 'PEUGEOT', 'model' => '307']));

        $rows = array_column($this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray()), null, 'id');

        self::assertSame(['MAZDA', '6 Hatchback (GG)', '2.0 (GGES)'], [$rows[$agent]['vehicle']['make'], $rows[$agent]['vehicle']['model'], $rows[$agent]['vehicle']['engine']]);
        self::assertSame(['AB123CD', 'MAZDA'], [$rows[$search]['vehicle']['plate'], $rows[$search]['vehicle']['make']]);
        self::assertSame('PEUGEOT', $rows[$named]['vehicle']['make']);

        static::getContainer()->get(DocumentManager::class)->clear();
        self::assertNull($this->stored($agent)->getVehicle()['make']);
    }

    public function testTheItemReadFillsToo(): void
    {
        $client = static::createClient();
        static::getContainer()->get(VehicleIndex::class)->record(16182, 'PC', 'MAZDA', '6 Hatchback (GG)', '2.0 (GGES)', 'catalog');
        $id = $this->seed(static fn (AgentTurn $t) => $t->setVehicle(['carId' => 16182, 'carType' => 'PC', 'plate' => null, 'make' => null, 'model' => null]));

        $item = $client->request('GET', '/agent/turns/'.$id, ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();

        self::assertSame('MAZDA', $item['vehicle']['make']);
    }

    public function testAnUnknownCarIdStaysUnresolved(): void
    {
        $client = static::createClient();
        $this->seed(static fn (AgentTurn $t) => $t->setVehicle(['carId' => 16182, 'carType' => 'PC', 'plate' => null, 'make' => null, 'model' => null]));

        $row = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray())[0];

        self::assertNull($row['vehicle']['make'] ?? null);
        self::assertSame(16182, $row['vehicle']['carId']);
    }

    /**
     * @param array<string, mixed> $search
     */
    private function seedSearch(array $search): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = AgentTurn::search(self::TEST_OWNER, 'filtre', $search, [], 10, AgentTurnStatus::Completed);
        $dm->persist($turn);
        $dm->flush();

        return (string) $turn->getId();
    }

    private function seed(\Closure $with): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn(self::TEST_OWNER, 'sess', 'x', 'ctx', 'anthropic', 'claude-sonnet-5-5', true);
        $turn->setSurface(TurnSurface::Finder);
        $with($turn);
        $turn->finish(AgentTurnStatus::Completed, null);
        $dm->persist($turn);
        $dm->flush();

        return (string) $turn->getId();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $data): array
    {
        return $data['member'] ?? $data['hydra:member'] ?? [];
    }

    private function stored(string $id): AgentTurn
    {
        return static::getContainer()->get(DocumentManager::class)->find(AgentTurn::class, $id);
    }
}
