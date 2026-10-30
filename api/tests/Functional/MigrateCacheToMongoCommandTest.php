<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\LegacyCacheNamespaces;
use App\Entity\MotulResponse;
use App\Motul\MotulResponseStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCacheToMongoCommandTest extends AppTestCase
{
    private const string RECS = 'recs.55f2787ad9c5b1f223e74fd945d820a460d89e71';
    private const string SEARCH = 'search.8dfd0ff78534b11449383e60daa7f02a758cdf60';

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->db = static::getContainer()->get('doctrine.dbal.default_connection');
        // Rolled back by DAMA with the rest of the test.
        $this->db->executeStatement('DELETE FROM cache_items');
    }

    public function testMigratesTheMotulPool(): void
    {
        $now = time();
        $this->seed('cache.motul', self::RECS, ['html' => '<html/>'], $now - 40);

        $tester = $this->migrate([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['html' => '<html/>'], static::getContainer()->get(MotulResponseStore::class)->find(self::RECS));
        $this->assertMatchesRegularExpression('/cache\.motul\s+\S+\s+1\s+1\s/', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seed('cache.motul', self::RECS, ['html' => '<html/>'], time());

        $tester = $this->migrate(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertNull(static::getContainer()->get(MotulResponseStore::class)->find(self::RECS));
        $this->assertMatchesRegularExpression('/cache\.motul\s+\S+\s+1\s+1\s/', $tester->getDisplay());
    }

    public function testIsIdempotent(): void
    {
        $this->seed('cache.motul', self::SEARCH, ['vehicles' => []], time());

        $this->migrate([]);
        $tester = $this->migrate([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(1, $this->rawAll(MotulResponse::class));
    }

    public function testFailsLoudlyOnAnUnrecognisedNamespaceUntilExplicitlyIgnored(): void
    {
        $this->db->insert('cache_items', ['item_id' => 'BxO5KZraMS:getSearchTree.x', 'item_data' => 'a:0:{}', 'item_lifetime' => null, 'item_time' => time()]);
        $this->seed('cache.motul', self::RECS, ['html' => '<html/>'], time());

        $failed = $this->migrate([]);
        $this->assertSame(1, $failed->getStatusCode());
        $this->assertStringContainsString('BxO5KZraMS', $failed->getDisplay());
        $this->assertNull(static::getContainer()->get(MotulResponseStore::class)->find(self::RECS), 'nothing is written when anything is unmapped');

        $ignored = $this->migrate(['--ignore-namespace' => ['BxO5KZraMS']]);
        $this->assertSame(0, $ignored->getStatusCode(), $ignored->getDisplay());
        $this->assertNotNull(static::getContainer()->get(MotulResponseStore::class)->find(self::RECS));
    }

    public function testFailsLoudlyOnAValueItCannotMap(): void
    {
        $this->seed('cache.motul', 'recs.not-a-sha', ['html' => '<html/>'], time());
        $this->seed('cache.motul', self::SEARCH, 'not an array', time());

        $tester = $this->migrate([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('recs.not-a-sha', $tester->getDisplay());
        $this->assertStringContainsString(self::SEARCH, $tester->getDisplay());
        $this->assertSame([], $this->rawAll(MotulResponse::class));
    }

    private function seed(string $pool, string $key, mixed $value, int $time, ?int $lifetime = null): void
    {
        $namespace = static::getContainer()->get(LegacyCacheNamespaces::class)->of($pool);
        $this->db->insert('cache_items', [
            'item_id' => $namespace.':'.$key,
            'item_data' => (new DefaultMarshaller())->marshall([$key => $value], $failed)[$key],
            'item_lifetime' => $lifetime,
            'item_time' => $time,
        ], ['item_data' => ParameterType::LARGE_OBJECT]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function migrate(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:cache:migrate-to-mongo'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawAll(string $class): array
    {
        $docs = [];
        foreach (static::getContainer()->get(DocumentManager::class)->getDocumentCollection($class)->find([], ['sort' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]) as $doc) {
            $docs[$doc['_id']] = $doc;
        }

        return $docs;
    }
}
