<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Vehicle\VehicleIndex;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillJournalVehiclesCommandTest extends AppTestCase
{
    public function testDryRunReportsWithoutWriting(): void
    {
        $ids = $this->seedRows();

        $tester = $this->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('2 row(s) resolvable', $display);
        self::assertStringContainsString('1 unresolved', $display);
        self::assertNull($this->stored($ids['agent'])->getVehicle()['make']);
        self::assertNull(static::getContainer()->get(VehicleIndex::class)->find(7777, 'PC'));
    }

    public function testApplyWritesNamesFromEverySourceAndLeavesUnknownCarsAlone(): void
    {
        $ids = $this->seedRows();

        $this->execute(['--apply' => true]);

        $agent = $this->stored($ids['agent'])->getVehicle();
        self::assertSame(['MAZDA', '6 Hatchback (GG)', '2.0 (GGES)'], [$agent['make'], $agent['model'], $agent['engine']]);
        $search = $this->stored($ids['search'])->getSearch();
        self::assertSame(['PEUGEOT', '307'], [$search['make'], $search['model']]);
        self::assertNull($this->stored($ids['unknown'])->getVehicle()['make']);

        $index = static::getContainer()->get(VehicleIndex::class);
        self::assertSame('journal', $index->find(9586, 'PC')['source']);
        self::assertSame('garage', $index->find(7777, 'PC')['source']);
        self::assertNull($index->find(16182, 'PC'));
    }

    /**
     * @return array{agent: string, search: string, unknown: string}
     */
    private function seedRows(): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        static::getContainer()->get(VehicleIndex::class)->record(16681, 'PC', 'MAZDA', '6 Hatchback (GG)', '2.0 (GGES)', 'catalog');

        $named = new AgentTurn(self::TEST_OWNER, 's', 'x', 'c', 'anthropic', 'm', true);
        $named->setSurface(TurnSurface::Finder);
        $named->setVehicle(['carId' => 9586, 'carType' => 'PC', 'plate' => null, 'make' => 'PEUGEOT', 'model' => '307', 'engine' => '1.6 HDi']);
        $named->finish(AgentTurnStatus::Completed, null);

        $agent = new AgentTurn(self::TEST_OWNER, 's', 'x', 'c', 'anthropic', 'm', true);
        $agent->setSurface(TurnSurface::Finder);
        $agent->setVehicle(['carId' => 16681, 'carType' => 'PC', 'plate' => null, 'make' => null, 'model' => null]);
        $agent->finish(AgentTurnStatus::Completed, null);

        $search = AgentTurn::search(self::TEST_OWNER, 'filtre', ['rule' => 'category', 'carId' => 9586, 'carType' => 'PC'], [], 5, AgentTurnStatus::Completed);

        $unknown = new AgentTurn(self::TEST_OWNER, 's', 'x', 'c', 'anthropic', 'm', true);
        $unknown->setSurface(TurnSurface::Finder);
        $unknown->setVehicle(['carId' => 16182, 'carType' => 'PC', 'plate' => null, 'make' => null, 'model' => null]);
        $unknown->finish(AgentTurnStatus::Completed, null);

        $garage = new Vehicle('RENAULT', 'Clio', VehicleType::Car, 'manual', engineVariant: '1.5 dCi', kType: 7777, owner: self::TEST_OWNER);

        foreach ([$named, $agent, $search, $unknown, $garage] as $doc) {
            $dm->persist($doc);
        }
        $dm->flush();
        $dm->clear();

        return ['agent' => (string) $agent->getId(), 'search' => (string) $search->getId(), 'unknown' => (string) $unknown->getId()];
    }

    private function stored(string $id): AgentTurn
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();

        return $dm->find(AgentTurn::class, $id);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::bootKernel()))->find('app:journal:backfill-vehicles'));
        $tester->execute($options);

        return $tester;
    }
}
