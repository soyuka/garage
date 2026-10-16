<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\ArticlePhoto;
use App\Catalog\ArticlePhotoStore;
use App\Command\LegacyCacheNamespaces;
use App\Entity\PartsApiResponse;
use App\Entity\TurnImageBatch;
use App\Motul\MotulResponseStore;
use App\Siv\SivLookupStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCacheToMongoCommandTest extends AppTestCase
{
    private const string GET_MAKES = 'getMakes.8dfd0ff78534b11449383e60daa7f02a758cdf60';
    private const string GET_ARTICLES = 'getArticles.3d7aa63567129c07408d60598fd5bcab162d4379';
    private const string RECS = 'recs.55f2787ad9c5b1f223e74fd945d820a460d89e71';
    private const string EXPIRED = 'getSearchTree.97ff21a115d6a2e60b1f29051da1bf103af7b480';

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->db = static::getContainer()->get('doctrine.dbal.default_connection');
        // Rolled back by DAMA with the rest of the test.
        $this->db->executeStatement('DELETE FROM cache_items');
    }

    public function testMigratesEveryPoolPreservingExpiry(): void
    {
        $now = time();
        $this->seed('cache.siv', 'AB123CD', ['code_erreur' => 200, 'data' => ['marque' => 'RENAULT']], $now - 50);
        $this->seed('cache.partsapi', self::GET_MAKES, [['makeId' => 93]], $now - 40);
        $this->seed('cache.partsapi', self::GET_ARTICLES, [['ART_ID' => 1]], $now - 30, 1000);
        $this->seed('cache.partsapi', self::EXPIRED, [['STR_ID' => 1]], $now - 3000, 1000);
        $this->seed('cache.partsapi', 'calls.getArticles', 50, $now);
        $this->seed('cache.motul', self::RECS, ['html' => '<html/>'], $now);
        $this->seed('cache.article_photos', 'photo.'.sha1('https://img/x.jpg'), new ArticlePhoto('image/jpeg', "\xFF\xD8bytes"), $now);
        $this->seed('cache.turn_images', 'turn_images_01K7Q0000000000000000000000', [['mimeType' => 'image/png', 'bytes' => base64_encode("\x89PNG")]], $now, 900);

        $tester = $this->migrate([]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['code_erreur' => 200, 'data' => ['marque' => 'RENAULT']], static::getContainer()->get(SivLookupStore::class)->find('AB123CD'));
        $this->assertSame(['html' => '<html/>'], static::getContainer()->get(MotulResponseStore::class)->find(self::RECS));
        $this->assertEquals(new ArticlePhoto('image/jpeg', "\xFF\xD8bytes"), static::getContainer()->get(ArticlePhotoStore::class)->find('https://img/x.jpg'));

        $parts = $this->rawAll(PartsApiResponse::class);
        $this->assertSame([self::GET_ARTICLES, self::GET_MAKES], array_keys($parts));
        $this->assertSame([['makeId' => 93]], $parts[self::GET_MAKES]['data']);
        $this->assertSame('getMakes', $parts[self::GET_MAKES]['method']);
        $this->assertNull($parts[self::GET_MAKES]['params']);
        $this->assertNull($parts[self::GET_MAKES]['expiresAt']);
        $this->assertSame($now - 40, $parts[self::GET_MAKES]['fetchedAt']->toDateTime()->getTimestamp());
        $this->assertSame($now - 30 + 1000, $parts[self::GET_ARTICLES]['expiresAt']->toDateTime()->getTimestamp());

        $images = $this->rawAll(TurnImageBatch::class);
        $this->assertSame("\x89PNG", $images['turn_images_01K7Q0000000000000000000000']['images'][0]['bytes']->getData());

        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/cache\.partsapi\s+\S+\s+4\s+2\s+dead call counter: 1, expired: 1/', $display);
        $this->assertMatchesRegularExpression('/cache\.siv\s+\S+\s+1\s+1\s/', $display);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seed('cache.siv', 'AB123CD', ['data' => []], time());

        $tester = $this->migrate(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertNull(static::getContainer()->get(SivLookupStore::class)->find('AB123CD'));
        $this->assertMatchesRegularExpression('/cache\.siv\s+\S+\s+1\s+1\s/', $tester->getDisplay());
    }

    public function testIsIdempotent(): void
    {
        $this->seed('cache.partsapi', self::GET_MAKES, [['makeId' => 93]], time());

        $this->migrate([]);
        $tester = $this->migrate([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(1, $this->rawAll(PartsApiResponse::class));
    }

    public function testFailsLoudlyOnAnUnrecognisedNamespaceUntilExplicitlyIgnored(): void
    {
        $this->db->insert('cache_items', ['item_id' => 'BxO5KZraMS:getSearchTree.x', 'item_data' => 'a:0:{}', 'item_lifetime' => null, 'item_time' => time()]);
        $this->seed('cache.siv', 'AB123CD', ['data' => []], time());

        $failed = $this->migrate([]);
        $this->assertSame(1, $failed->getStatusCode());
        $this->assertStringContainsString('BxO5KZraMS', $failed->getDisplay());
        $this->assertNull(static::getContainer()->get(SivLookupStore::class)->find('AB123CD'), 'nothing is written when anything is unmapped');

        $ignored = $this->migrate(['--ignore-namespace' => ['BxO5KZraMS']]);
        $this->assertSame(0, $ignored->getStatusCode(), $ignored->getDisplay());
        $this->assertNotNull(static::getContainer()->get(SivLookupStore::class)->find('AB123CD'));
    }

    public function testFailsLoudlyOnAValueItCannotMap(): void
    {
        $this->seed('cache.partsapi', 'getMakes.not-a-sha', [['makeId' => 93]], time());
        $this->seed('cache.siv', 'AB123CD', 'not an envelope', time());

        $tester = $this->migrate([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('getMakes.not-a-sha', $tester->getDisplay());
        $this->assertStringContainsString('AB123CD', $tester->getDisplay());
        $this->assertSame([], $this->rawAll(PartsApiResponse::class));
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
