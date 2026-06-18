<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Locks the index plan worked out in the schema review: the Vehicle collection must be indexed for
 * its owner-scoped identity lookups (with a case-insensitive collation so the queries drop the
 * index-defeating `i`-flag regex), and the MaintenanceRecord history queries must be served by
 * sort-covering compounds — never the redundant single-field prefix.
 *
 * Reads the mapping metadata only (no Mongo round-trip), so it is a pure unit guard on the schema.
 */
final class IndexMappingTest extends KernelTestCase
{
    private static function indexesOf(string $document): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);

        return $dm->getClassMetadata($document)->getIndexes();
    }

    private static function hasIndexWithKeys(array $indexes, array $keys): bool
    {
        foreach ($indexes as $index) {
            if ($index['keys'] === $keys) {
                return true;
            }
        }

        return false;
    }

    public function testVehicleHasCaseInsensitiveIdentityIndex(): void
    {
        self::bootKernel();
        $indexes = self::indexesOf(Vehicle::class);

        $identity = null;
        foreach ($indexes as $index) {
            if ($index['keys'] === ['owner' => 1, 'vehicleType' => 1, 'make' => 1, 'model' => 1, 'year' => 1]) {
                $identity = $index;
                break;
            }
        }

        $this->assertNotNull($identity, 'Vehicle must declare the owner-scoped identity index.');
        $this->assertSame(
            ['locale' => 'fr', 'strength' => 2],
            $identity['options']['collation'] ?? null,
            'The identity index must carry the case-insensitive collation the queries rely on.',
        );
    }

    public function testVehicleHasOwnerAddedAtIndexForTheSharedListing(): void
    {
        self::bootKernel();

        $this->assertTrue(
            self::hasIndexWithKeys(self::indexesOf(Vehicle::class), ['owner' => 1, 'addedAt' => -1]),
            'findShared sorts by addedAt desc within an owner; that sort must be index-covered.',
        );
    }

    public function testMaintenanceRecordHistoryIndexesAreSortCovering(): void
    {
        self::bootKernel();
        $indexes = self::indexesOf(MaintenanceRecord::class);

        $this->assertTrue(
            self::hasIndexWithKeys($indexes, ['vehicle' => 1, 'performedAt' => -1, 'id' => -1]),
            'Unfiltered history() must be served index-sorted (vehicle, performedAt desc, id desc).',
        );
        $this->assertTrue(
            self::hasIndexWithKeys($indexes, ['vehicle' => 1, 'type' => 1, 'performedAt' => -1]),
            'Typed history() must be served index-sorted (vehicle, type, performedAt desc).',
        );
    }

    public function testMaintenanceRecordDropsTheRedundantSingleFieldIndex(): void
    {
        self::bootKernel();

        $this->assertFalse(
            self::hasIndexWithKeys(self::indexesOf(MaintenanceRecord::class), ['vehicle' => 1]),
            'The single-field {vehicle} index is a prefix of the compounds — it must be removed.',
        );
    }
}
