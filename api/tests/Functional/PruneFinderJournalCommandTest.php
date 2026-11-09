<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneFinderJournalCommandTest extends AppTestCase
{
    private const array FINDER_FIELDS = ['surface', 'kind', 'search', 'vehicle', 'images', 'partsApiCalls', 'partsApiCallsByMethod', 'categoryLabel', 'searchId', 'hiddenFromHistory'];

    public function testDeletesFinderRowsAndStripsTheFinderFieldsFromGarageRows(): void
    {
        $turns = $this->turns();
        $turns->insertMany([
            ['_id' => 'finder-search', 'owner' => self::TEST_OWNER, 'surface' => 'finder', 'kind' => 'search', 'search' => ['rule' => 'plate'], 'searchId' => 's1'],
            ['_id' => 'finder-agent', 'owner' => self::TEST_OWNER, 'surface' => 'finder', 'kind' => 'agent', 'vehicle' => ['carId' => 19107, 'carType' => 'PC'], 'partsApiCalls' => 3],
            ['_id' => 'garage', 'owner' => self::TEST_OWNER, 'instruction' => 'vidange faite', 'surface' => 'garage', 'kind' => 'agent', 'search' => [], 'images' => [], 'partsApiCallsByMethod' => [], 'hiddenFromHistory' => false],
        ]);
        $turns->createIndex(['owner' => 1, 'searchId' => 1], ['name' => 'idx_turn_owner_search', 'sparse' => true]);
        $turns->createIndex(['owner' => 1, 'surface' => 1, 'startedAt' => -1], ['name' => 'idx_turn_owner_surface_started']);

        $tester = $this->prune();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $turns->countDocuments(['_id' => ['$in' => ['finder-search', 'finder-agent']]]));
        $garage = $turns->findOne(['_id' => 'garage'], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        $this->assertIsArray($garage);
        $this->assertSame('vidange faite', $garage['instruction']);
        foreach (self::FINDER_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $garage);
        }
        $indexes = array_map(static fn ($index): string => $index->getName(), iterator_to_array($turns->listIndexes()));
        $this->assertNotContains('idx_turn_owner_search', $indexes);
        $this->assertNotContains('idx_turn_owner_surface_started', $indexes);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->turns()->insertOne(['_id' => 'garage', 'owner' => self::TEST_OWNER, 'surface' => 'garage']);
        $this->prune();

        $tester = $this->prune();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('0 finder row(s) deleted, 0 garage row(s) cleaned', $tester->getDisplay());
    }

    private function turns(): Collection
    {
        return static::getContainer()->get(DocumentManager::class)->getDocumentCollection(AgentTurn::class);
    }

    private function prune(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:agent-turns:prune-finder'));
        $tester->execute([]);

        return $tester;
    }
}
