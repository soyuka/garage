<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Storage\VehicleDocumentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class DemoGarageLoadCommandTest extends AppTestCase
{
    private const string DEMO = 'demo@example.com';
    private const string CAR = '01KVFEQA72XQMWSS3HPBD93AAA';
    private const string TASK = '01KVFEQADF7DKT0HBCWS7S39AA';
    private const string OLD_DOCUMENT = '01M3YRW441X5WBJHZWYNR6BQAA';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/demo-load-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/documents', recursive: true);
        file_put_contents($this->dir.'/documents/'.self::OLD_DOCUMENT.'.pdf', PdfFixture::renaultManual());
        file_put_contents($this->dir.'/garage.json', json_encode([
            'vehicles' => [[
                '_id' => self::CAR,
                'addedAt' => ['$date' => '2026-06-19T08:07:26.69Z'],
                'deletedAt' => null,
                'engineCode' => '2.0 MZR147 LFF7',
                'engineVariant' => 'GG',
                'kType' => 19107,
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'plate' => 'XB-301-ZB',
                'serviceSchedule' => ['oil_change:change' => [
                    'id' => '01KVT9YVBD855HKT6K3G8GPKAA', 'type' => 'oil_change', 'action' => 'change', 'source' => 'web',
                    'everyUsageValue' => 20000, 'everyUsageUnit' => 'km', 'everyMonths' => 12,
                    'addedAt' => ['$date' => '2026-06-23T13:15:49.485Z'], 'deletedAt' => null, 'verified' => false,
                    'component' => null, 'firstUsageValue' => null, 'url' => null, 'addedBy' => null,
                ]],
                'source' => 'user',
                'suppressedFactKeys' => new \stdClass(),
                'usageUnit' => 'km',
                'usageValue' => 252524,
                'vehicleType' => 'car',
                'vin' => 'JMZGG14F671528417',
                'year' => 2007,
            ]],
            'maintenanceRecords' => [[
                '_id' => '01KVV6VY3TJSTVEQV8CZHEK5AA',
                'addedAt' => ['$date' => '2026-06-23T21:41:02.714Z'],
                'addedBy' => null,
                'costCents' => null,
                'currency' => null,
                'label' => null,
                'notes' => null,
                'performedBy' => 'self',
                'items' => [['type' => 'oil_change', 'parts' => [['ref' => '5W-30 9000 NFC', 'brand' => 'Total Quartz']]]],
                'performedAt' => ['$date' => '2023-03-26T00:00:00Z'],
                'source' => 'user',
                'usageUnit' => 'km',
                'usageValue' => 205731,
                'vehicle' => self::CAR,
            ]],
            'plannedMaintenance' => [[
                '_id' => self::TASK,
                'attributes' => ['oil_type' => [
                    'key' => 'oil_type', 'value' => 'ACEA A3/B3', 'source' => 'document', 'verified' => true,
                    'addedAt' => ['$date' => '2026-06-19T08:07:26.895Z'], 'unit' => null, 'addedBy' => null,
                    'url' => \sprintf('/garage/vehicles/%s/documents/%s/file', self::CAR, self::OLD_DOCUMENT),
                ]],
                'addedBy' => null,
                'completedAt' => null,
                'createdAt' => ['$date' => '2026-06-19T08:07:26.895Z'],
                'dueDate' => null,
                'label' => null,
                'parts' => [],
                'targetUsageUnit' => null,
                'targetUsageValue' => null,
                'source' => 'partsapi',
                'status' => 'open',
                'type' => 'catalog_specs',
                'vehicle' => self::CAR,
            ]],
            'documents' => [[
                'id' => self::OLD_DOCUMENT,
                'vehicle' => self::CAR,
                'file' => 'documents/'.self::OLD_DOCUMENT.'.pdf',
                'filename' => 'MR-361-Espace-IV-Transmission.pdf',
                'kind' => 'manual',
                'source' => 'dialogys',
                'sourceRef' => 'MR 361 — Transmission',
                'title' => 'Manuel de réparation — Transmission',
                'plannedTaskId' => null,
            ]],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testLoadsTheFixtureForTheOwnerAndReplacesTheirGarage(): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->getDocumentCollection(Vehicle::class)->insertMany([
            ['_id' => '01STALEDEMOVEHICLE00000000', 'make' => 'STALE', 'model' => 'X', 'owner' => self::DEMO, 'source' => 'user', 'vehicleType' => 'car'],
            ['_id' => '01SOMEONEELSEVEHICLE000000', 'make' => 'KEPT', 'model' => 'X', 'owner' => 'someone@example.com', 'source' => 'user', 'vehicleType' => 'car'],
        ]);

        $this->assertSame(Command::SUCCESS, $this->load()->getStatusCode());
        $this->assertSame(Command::SUCCESS, $this->load()->getStatusCode(), 'reloading must replace, not duplicate');

        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();
        $this->assertSame(1, $dm->getDocumentCollection(Vehicle::class)->countDocuments(['owner' => self::DEMO]));
        $this->assertSame(1, $dm->getDocumentCollection(Vehicle::class)->countDocuments(['owner' => 'someone@example.com']));
        $this->assertSame(1, $dm->getDocumentCollection(MaintenanceRecord::class)->countDocuments(['owner' => self::DEMO]));
        $this->assertSame(1, $dm->getDocumentCollection(PlannedMaintenance::class)->countDocuments(['owner' => self::DEMO]));

        $vehicle = $dm->find(Vehicle::class, self::CAR);
        $this->assertInstanceOf(Vehicle::class, $vehicle);
        $this->assertSame(self::DEMO, $vehicle->getOwner());
        $this->assertSame('XB-301-ZB', $vehicle->getPlate());
        $records = $vehicle->getMaintenanceRecords()->toArray();
        $this->assertCount(1, $records);
        $this->assertSame(205731, $records[0]->getUsageValue());

        $documents = $dm->getRepository(VehicleDocument::class)->findBy(['owner' => self::DEMO]);
        $this->assertCount(1, $documents);
        $document = $documents[0];
        $this->assertSame('MR 361 — Transmission', $document->getSourceRef());
        $this->assertSame('MR-361-Espace-IV-Transmission.pdf', $document->getFilename());
        $this->assertGreaterThan(0, $document->getPages()->count());
        $this->assertSame(PdfFixture::renaultManual(), stream_get_contents(static::getContainer()->get(VehicleDocumentStorage::class)->readStream($document->getStorageKey())));

        $task = $dm->getDocumentCollection(PlannedMaintenance::class)->findOne(['_id' => self::TASK]);
        $this->assertSame(
            \sprintf('/garage/vehicles/%s/documents/%s/file', self::CAR, $document->getId()),
            $task['attributes']['oil_type']['url'] ?? null,
        );
    }

    private function load(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:demo:load'));
        $tester->execute(['fixture' => $this->dir.'/garage.json', '--owner' => self::DEMO]);

        return $tester;
    }
}
