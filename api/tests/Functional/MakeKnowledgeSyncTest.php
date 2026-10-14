<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MakeKnowledge;
use App\Repository\MakeKnowledgeRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class MakeKnowledgeSyncTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/make-knowledge-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeFile(string $name, array $entries): void
    {
        file_put_contents($this->dir.'/'.$name, Yaml::dump(['entries' => $entries], 6, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $topic = 'numero-organisation', string $body = 'Le compteur.'): array
    {
        return [
            'makes' => ['Peugeot', 'CITROËN'],
            'topic' => $topic,
            'title' => 'Numéro d\'organisation',
            'verified' => true,
            'body' => $body,
            'sources' => [['url' => 'https://example.test/a', 'label' => 'Forum', 'verified' => true]],
        ];
    }

    private function sync(): CommandTester
    {
        static::bootKernel();
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:make-knowledge:sync'));
        $tester->execute(['--path' => $this->dir]);

        return $tester;
    }

    private function repository(): MakeKnowledgeRepository
    {
        $repository = static::getContainer()->get(MakeKnowledgeRepository::class);
        \assert($repository instanceof MakeKnowledgeRepository);

        return $repository;
    }

    private function clear(): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $dm->clear();
    }

    public function testCreatesEntriesWithNormalizedMakes(): void
    {
        $this->writeFile('psa.yaml', [self::entry()]);

        $tester = $this->sync();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $entry = $this->repository()->findOneForMake('peugeot', 'numero-organisation');
        $this->assertInstanceOf(MakeKnowledge::class, $entry);
        $this->assertSame(['CITROEN', 'PEUGEOT'], $entry->getMakes());
        $this->assertSame('Le compteur.', $entry->getBody());
        $this->assertTrue($entry->isVerified());
        $this->assertSame('https://example.test/a', $entry->getSources()[0]->getUrl());
        $this->assertNotNull($this->repository()->findOneForMake('Citroën', 'numero-organisation'));
    }

    public function testResyncIsIdempotent(): void
    {
        $this->writeFile('psa.yaml', [self::entry()]);
        $this->sync();
        $this->clear();
        $first = $this->repository()->findOneForMake('PEUGEOT', 'numero-organisation');
        $id = $first->getId();
        $updatedAt = $first->getUpdatedAt();
        $this->clear();

        $tester = $this->sync();

        $this->assertStringContainsString('0 created, 0 updated, 0 removed, 1 unchanged', $tester->getDisplay());
        $this->clear();
        $second = $this->repository()->findOneForMake('PEUGEOT', 'numero-organisation');
        $this->assertSame($id, $second->getId());
        $this->assertEquals($updatedAt, $second->getUpdatedAt());
        $this->assertCount(1, $this->repository()->findForMake('PEUGEOT'));
    }

    public function testUpdatesAChangedEntryInPlace(): void
    {
        $this->writeFile('psa.yaml', [self::entry()]);
        $this->sync();
        $id = $this->repository()->findOneForMake('PEUGEOT', 'numero-organisation')->getId();
        $this->clear();

        $this->writeFile('psa.yaml', [self::entry(body: 'Nouveau corps.')]);
        $tester = $this->sync();

        $this->assertStringContainsString('0 created, 1 updated', $tester->getDisplay());
        $this->clear();
        $entry = $this->repository()->findOneForMake('PEUGEOT', 'numero-organisation');
        $this->assertSame($id, $entry->getId());
        $this->assertSame('Nouveau corps.', $entry->getBody());
    }

    public function testRemovesEntriesThatDisappearedFromTheFiles(): void
    {
        $this->writeFile('psa.yaml', [self::entry(), self::entry('ou-trouver-opr')]);
        $this->sync();
        $this->clear();

        $this->writeFile('psa.yaml', [self::entry()]);
        $tester = $this->sync();

        $this->assertStringContainsString('1 removed', $tester->getDisplay());
        $this->clear();
        $this->assertNull($this->repository()->findOneForMake('PEUGEOT', 'ou-trouver-opr'));
        $this->assertNotNull($this->repository()->findOneForMake('PEUGEOT', 'numero-organisation'));
    }

    public function testRejectsAnEntryWithoutATopicAndWritesNothing(): void
    {
        $broken = self::entry();
        unset($broken['topic']);
        $this->writeFile('psa.yaml', [self::entry(), $broken]);

        $tester = $this->sync();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('topic', $tester->getDisplay());
        $this->clear();
        $this->assertSame([], $this->repository()->findForMake('PEUGEOT'));
    }

    public function testRejectsADuplicateMakeAndTopic(): void
    {
        $this->writeFile('psa.yaml', [self::entry(), self::entry()]);

        $tester = $this->sync();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Duplicate', $tester->getDisplay());
    }

    public function testTheShippedPsaFileIsValid(): void
    {
        static::bootKernel();
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:make-knowledge:sync'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $topics = array_map(static fn (MakeKnowledge $e) => $e->getTopic(), $this->repository()->findForMake('Citroën'));
        sort($topics);
        $this->assertSame(['codes-usine', 'numero-organisation', 'ou-trouver-opr', 'vin-decodage'], $topics);
        foreach ($this->repository()->findForMake('DS') as $entry) {
            $this->assertNotEmpty($entry->getSources(), $entry->getTopic());
            $this->assertTrue($entry->isVerified());
        }
    }
}
