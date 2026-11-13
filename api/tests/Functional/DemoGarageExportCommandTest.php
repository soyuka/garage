<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentRecorder;
use App\Documentation\VehicleDocumentSource;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class DemoGarageExportCommandTest extends AppTestCase
{
    private const string OWNER = 'real.owner@example.com';
    private const string CAR = '01KVFEQA72XQMWSS3HPBD93AAA';
    private const string MOWER = '01KVKMEFM6V36AWETKE7A9QAAA';
    private const string OTHER = '01M3KFPM06Y3EGGPQGMCQ1NAAA';
    private const string BIKE = '01KZDEMOBMWF800GSA2014AAAA';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/demo-export-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/mapping.txt', implode("\n", [
            '# real -> fake',
            'plate:AB-123-CD==>XB-301-ZB',
            'regex:(?<=VF1TEST0)123456==>654321',
            'regex:Jean Dupont==>the owner',
            'regex:(?<=parcel )[0-9]+==>0000000',
            '',
        ]));
        file_put_contents($this->dir.'/extras.json', json_encode(['vehicles' => [[
            '_id' => self::BIKE,
            'addedAt' => ['$date' => '2026-11-13T00:00:00Z'],
            'kType' => 101222,
            'make' => 'BMW',
            'model' => 'F 800 GS Adventure',
            'plate' => 'XH-307-ZH',
            'source' => 'user',
            'vehicleType' => 'moto',
            'year' => 2014,
        ]]], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testExportsTheSelectedVehiclesWithFakeIdentifiersAndNoOwner(): void
    {
        [$manualId, $quoteId] = $this->seedGarage();

        $tester = $this->export();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $json = (string) file_get_contents($this->dir.'/out/garage.json');
        foreach ([self::OWNER, 'AB-123-CD', 'ab 123 cd', 'VF1TEST0123456', 'Jean Dupont', 'secret-bill.pdf', self::OTHER, $quoteId] as $real) {
            $this->assertStringNotContainsString($real, $json);
        }
        $this->assertStringContainsString('XB-301-ZB', $json);
        $this->assertStringContainsString('xb 301 zb', $json);
        $this->assertStringContainsString('VF1TEST0654321', $json);
        $this->assertStringContainsString('parcel 0000000', $json);
        $this->assertStringNotContainsString('1734746', $json);
        $this->assertStringNotContainsString('"owner"', $json);

        $fixture = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame([self::CAR, self::MOWER, self::BIKE], array_column($fixture['vehicles'], '_id'));
        $this->assertSame([], $fixture['maintenanceRecords'][0]['attachments']);
        $this->assertSame(['$date' => '2023-03-26T00:00:00Z'], $fixture['maintenanceRecords'][0]['performedAt']);

        $attributes = $fixture['plannedMaintenance'][0]['attributes'];
        $this->assertNull($attributes['oil_capacity']['url']);
        $this->assertSame(\sprintf('/garage/vehicles/%s/documents/%s/file', self::CAR, $manualId), $attributes['oil_type']['url']);

        $this->assertCount(1, $fixture['documents']);
        $document = $fixture['documents'][0];
        $this->assertSame($manualId, $document['id']);
        $this->assertSame('manual', $document['kind']);
        $this->assertSame('MR 361 — Transmission', $document['sourceRef']);
        $this->assertSame(PdfFixture::renaultManual(), file_get_contents($this->dir.'/out/'.$document['file']));
        $this->assertSame(['documents/'.$manualId.'.pdf'], $this->relativeFiles($this->dir.'/out/documents'));

        $this->assertSame(Command::SUCCESS, $this->export()->getStatusCode());
        $this->assertSame($json, file_get_contents($this->dir.'/out/garage.json'), 'a re-export of the same garage must be byte-identical');
    }

    public function testRefusesToWriteARealValueTheMappingDoesNotCover(): void
    {
        $this->seedGarage(vin: 'VF1UNMAPPED987654');

        $tester = $this->export();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('vin', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->dir.'/out/garage.json');
    }

    public function testStopsAndListsTheDocumentsWhenTheyExceedTheSizeCap(): void
    {
        $this->seedGarage();

        $tester = $this->export(['--max-documents-bytes' => '100']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Espace IV — Transmission', $display);
        $this->assertStringContainsString((string) \strlen(PdfFixture::renaultManual()), $display);
        $this->assertFileDoesNotExist($this->dir.'/out/garage.json');
    }

    /**
     * @param array<string, string> $options
     */
    private function export(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:demo:export'));
        $tester->execute([
            '--owner' => self::OWNER,
            '--mapping' => $this->dir.'/mapping.txt',
            '--vehicle' => [self::MOWER, self::CAR],
            '--extras' => $this->dir.'/extras.json',
            '--out' => $this->dir.'/out/garage.json',
            ...$options,
        ]);

        return $tester;
    }

    /**
     * @return array{string, string} the manual's and the quote's document ids
     */
    private function seedGarage(string $vin = 'VF1TEST0123456'): array
    {
        $container = static::getContainer();
        $dm = $container->get(DocumentManager::class);
        $now = new UTCDateTime(new \DateTimeImmutable('2026-06-19T08:07:26Z'));

        $vehicle = static fn (string $id, string $make, string $model, string $type, ?string $plate, ?string $vin, int $year): array => [
            '_id' => $id, 'addedAt' => $now, 'deletedAt' => null, 'engineCode' => null, 'engineVariant' => null, 'kType' => null,
            'make' => $make, 'model' => $model, 'owner' => self::OWNER, 'plate' => $plate, 'source' => 'user',
            'usageUnit' => null, 'usageValue' => null, 'vehicleType' => $type, 'vin' => $vin, 'year' => $year,
        ];
        $dm->getDocumentCollection(Vehicle::class)->insertMany([
            $vehicle(self::CAR, 'RENAULT', 'ESPACE IV', 'car', 'AB-123-CD', $vin, 2006),
            $vehicle(self::MOWER, 'TORO', 'Wheel Horse 246-H', 'groundscare', null, null, 1992),
            $vehicle(self::OTHER, 'PEUGEOT', '206', 'car', 'ZZ-999-ZZ', null, 2004),
        ]);

        $owner = $container->get(OwnerContext::class);
        $owner->set(self::OWNER);
        $car = $dm->find(Vehicle::class, self::CAR);
        $this->assertInstanceOf(Vehicle::class, $car);
        $recorder = $container->get(VehicleDocumentRecorder::class);
        file_put_contents($this->dir.'/manual.pdf', PdfFixture::renaultManual());
        file_put_contents($this->dir.'/quote.pdf', PdfFixture::build([['Facture Jean Dupont', 'AB-123-CD']]));
        $manual = $recorder->record($car, $this->dir.'/manual.pdf', 'MR-361.pdf', VehicleDocumentKind::Manual, VehicleDocumentSource::Dialogys, 'Espace IV — Transmission', 'MR 361 — Transmission');
        $quote = $recorder->record($car, $this->dir.'/quote.pdf', 'pre-facture.pdf', VehicleDocumentKind::Quote, VehicleDocumentSource::Upload, 'Pré-facture');

        $dm->getDocumentCollection(MaintenanceRecord::class)->insertOne([
            '_id' => '01KVV6VY3TJSTVEQV8CZHEK5AA',
            'addedAt' => $now,
            'attachments' => [['id' => '01KVQ1TWGEJR7N4B6CAMSJH5AA', 'filename' => 'secret-bill.pdf', 'storageKey' => 'x/y']],
            'items' => [['type' => 'oil_change', 'parts' => [['ref' => '5W-30', 'brand' => 'Total']]]],
            'addedBy' => null,
            'costCents' => null,
            'currency' => null,
            'label' => null,
            'notes' => 'Parts bought by Jean Dupont for ab 123 cd, parcel 1734746.',
            'owner' => self::OWNER,
            'performedBy' => 'self',
            'performedAt' => new UTCDateTime(new \DateTimeImmutable('2023-03-26T00:00:00Z')),
            'source' => 'user',
            'usageUnit' => 'km',
            'usageValue' => 205731,
            'vehicle' => self::CAR,
        ]);

        $documentUrl = static fn (string $id): string => \sprintf('/garage/vehicles/%s/documents/%s/file', self::CAR, $id);
        $dm->getDocumentCollection(PlannedMaintenance::class)->insertMany([
            [
                '_id' => '01KVFEQADF7DKT0HBCWS7S39AA',
                'attributes' => [
                    'oil_type' => ['key' => 'oil_type', 'value' => 'ACEA A3/B3', 'url' => $documentUrl($manual->getId())],
                    'oil_capacity' => ['key' => 'oil_capacity', 'value' => '4.3', 'url' => $documentUrl($quote->getId())],
                ],
                'addedBy' => null,
                'completedAt' => null,
                'createdAt' => $now,
                'dueDate' => null,
                'label' => null,
                'owner' => self::OWNER,
                'parts' => [],
                'source' => 'partsapi',
                'targetUsageUnit' => null,
                'targetUsageValue' => null,
                'status' => 'open',
                'type' => 'catalog_specs',
                'vehicle' => self::CAR,
            ],
            ['_id' => '01M3KFY846R3Q81267T1HKGCAA', 'createdAt' => $now, 'owner' => self::OWNER, 'parts' => [], 'source' => 'user', 'status' => 'open', 'type' => 'oil_change', 'vehicle' => self::OTHER],
        ]);

        return [$manual->getId(), $quote->getId()];
    }

    /**
     * @return list<string>
     */
    private function relativeFiles(string $dir): array
    {
        $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));

        return array_map(static fn (string $file): string => 'documents/'.$file, $files);
    }
}
