<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Entity\AgentTurn;
use App\Entity\TurnCancellation;
use App\Entity\TurnImageBatch;
use App\Entity\User;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads the mapping metadata only (no Mongo round-trip), so it is a pure unit guard on the schema.
 */
final class IndexMappingTest extends KernelTestCase
{
    /**
     * @param class-string $document
     *
     * @return array<array{keys: array<string, mixed>, options: array<string, mixed>}>
     */
    private static function indexesOf(string $document): array
    {
        self::bootKernel();
        $dm = static::getContainer()->get(DocumentManager::class);

        return $dm->getClassMetadata($document)->getIndexes();
    }

    /**
     * @param array<array{keys: array<string, mixed>, options: array<string, mixed>}> $indexes
     * @param array<string, mixed>                                                    $keys
     *
     * @return array{keys: array<string, mixed>, options: array<string, mixed>}|null
     */
    private static function indexWithKeys(array $indexes, array $keys): ?array
    {
        foreach ($indexes as $index) {
            if ($index['keys'] === $keys) {
                return $index;
            }
        }

        return null;
    }

    public function testAgentTurnHistoryIndexesAreSortCovering(): void
    {
        $indexes = self::indexesOf(AgentTurn::class);

        $this->assertNotNull(self::indexWithKeys($indexes, ['owner' => 1, 'startedAt' => -1]), 'The owner journal listing sorts by startedAt desc.');
        $this->assertNotNull(self::indexWithKeys($indexes, ['owner' => 1, 'surface' => 1, 'startedAt' => -1]), 'The per-surface journal listing sorts by startedAt desc.');
        $this->assertTrue(self::indexWithKeys($indexes, ['owner' => 1, 'searchId' => 1])['options']['sparse'] ?? false, 'Only turns of a finder search carry a searchId.');
    }

    public function testTransientCollectionsExpireThroughATtlIndex(): void
    {
        foreach ([TurnImageBatch::class, TurnCancellation::class] as $document) {
            $ttl = self::indexWithKeys(self::indexesOf($document), ['expiresAt' => 1]);

            $this->assertNotNull($ttl, $document);
            $this->assertSame(0, $ttl['options']['expireAfterSeconds'] ?? null, $document);
        }
    }

    public function testUserIsUniquelyKeyedByItsIdentifier(): void
    {
        $unique = array_filter(self::indexesOf(User::class), static fn (array $index): bool => true === ($index['options']['unique'] ?? false));

        $this->assertNotSame([], $unique);
    }
}
