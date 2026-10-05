<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VehicleDocumentTest extends AppTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    private function seedVehicle(string $owner = self::TEST_OWNER, string $model = 'CLIO'): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('RENAULT', $model, VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner($owner);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    private function seedPlannedTask(Vehicle $vehicle): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $task = new PlannedMaintenance($dm->find(Vehicle::class, (string) $vehicle->getId()), 'gearbox_oil', 'agent');
        $dm->persist($task);
        $dm->flush();
        $dm->clear();

        return (string) $task->getId();
    }

    private function tmpUpload(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mpa_doc_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, test: true);
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function bearer(string $email = self::TEST_OWNER): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for($email, $email)];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function upload(KernelBrowser $browser, Vehicle $vehicle, UploadedFile $file, array $params = [], ?string $email = null, int $expect = 201): array
    {
        $browser->request('POST', '/garage/vehicles/'.$vehicle->getId().'/documents', $params + ['kind' => 'manual'], ['file' => $file], $this->bearer($email ?? self::TEST_OWNER));
        self::assertSame($expect, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return 201 === $expect ? json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR) : [];
    }

    public function testUploadExtractsPagesAndListsWithoutText(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();

        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'mr361.pdf', 'application/pdf'), [
            'title' => 'MR 361 Transmission',
            'sourceRef' => 'MR 361 — Transmission',
        ]);

        self::assertSame('manual', $doc['kind']);
        self::assertSame('upload', $doc['source']);
        self::assertSame('MR 361 — Transmission', $doc['sourceRef']);
        self::assertSame('mr361.pdf', $doc['filename']);
        self::assertSame('application/pdf', $doc['contentType']);
        self::assertSame(2, $doc['pageCount']);
        self::assertNull($doc['plannedTaskId']);
        self::assertSame('/garage/vehicles/'.$vehicle->getId().'/documents/'.$doc['id'].'/file', $doc['url']);
        self::assertSame('/garage/vehicles/'.$vehicle->getId().'/documents/'.$doc['id'].'/pages', $doc['pagesUrl']);

        $browser->request('GET', '/garage/vehicles/'.$vehicle->getId().'/documents', [], [], $this->bearer());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $list = json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $list);
        self::assertSame($doc['id'], $list[0]['id']);
        self::assertArrayNotHasKey('pages', $list[0]);
        self::assertStringNotContainsString('Couple de serrage', (string) $browser->getResponse()->getContent());
    }

    public function testImageIsStoredWithoutPages(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();

        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(self::PNG, 'devis.png', 'image/png'), ['kind' => 'quote']);

        self::assertSame('quote', $doc['kind']);
        self::assertSame(0, $doc['pageCount']);
        self::assertSame('devis.png', $doc['title']);
    }

    public function testFileIsStreamedBackInline(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $pdf = PdfFixture::renaultManual();

        $doc = $this->upload($browser, $vehicle, $this->tmpUpload($pdf, 'mr361.pdf', 'application/pdf'));

        $browser->request('GET', $doc['url'], [], [], $this->bearer());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $browser->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $browser->getResponse()->headers->get('Content-Disposition'));
        self::assertSame($pdf, $browser->getInternalResponse()->getContent());
    }

    public function testPagesEndpointListsNumbersAndLabelsWithoutText(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'mr361.pdf', 'application/pdf'));

        $browser->request('GET', $doc['pagesUrl'], [], [], $this->bearer());

        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $content = (string) $browser->getResponse()->getContent();
        self::assertSame([['number' => 1, 'label' => '21A-5'], ['number' => 2, 'label' => '21A-6']], json_decode($content, true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Couple de serrage', $content);
    }

    public function testPagesEndpointIs404ForForeignOwnerAndUnknownDocument(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'mr361.pdf', 'application/pdf'));
        $base = '/garage/vehicles/'.$vehicle->getId().'/documents';

        $browser->request('GET', $doc['pagesUrl'], [], [], $this->bearer('intruder@example.com'));
        self::assertSame(404, $browser->getResponse()->getStatusCode());

        $browser->request('GET', $base.'/unknown/pages', [], [], $this->bearer());
        self::assertSame(404, $browser->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheDocument(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'mr361.pdf', 'application/pdf'));

        $browser->request('DELETE', '/garage/vehicles/'.$vehicle->getId().'/documents/'.$doc['id'], [], [], $this->bearer());
        self::assertSame(204, $browser->getResponse()->getStatusCode());

        $browser->request('GET', '/garage/vehicles/'.$vehicle->getId().'/documents', [], [], $this->bearer());
        self::assertSame([], json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));

        $browser->request('GET', $doc['url'], [], [], $this->bearer());
        self::assertSame(404, $browser->getResponse()->getStatusCode());
    }

    public function testRejectsUnsupportedTypeMissingFileAndBadKind(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();

        $this->upload($browser, $vehicle, $this->tmpUpload("plain text\n", 'a.txt', 'text/plain'), expect: 422);
        $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'a.pdf', 'application/pdf'), ['kind' => 'recipe'], expect: 422);

        $browser->request('POST', '/garage/vehicles/'.$vehicle->getId().'/documents', ['kind' => 'manual'], [], $this->bearer());
        self::assertSame(422, $browser->getResponse()->getStatusCode());
    }

    public function testQuoteLinksToAPlannedTaskOfTheSameVehicle(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $taskId = $this->seedPlannedTask($vehicle);

        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(self::PNG, 'devis.png', 'image/png'), ['kind' => 'quote', 'plannedTaskId' => $taskId]);

        self::assertSame($taskId, $doc['plannedTaskId']);
    }

    public function testQuoteLinkedToAForeignVehiclesTaskIs422(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $other = $this->seedVehicle(model: 'MEGANE');
        $foreignTask = $this->seedPlannedTask($other);

        $this->upload($browser, $vehicle, $this->tmpUpload(self::PNG, 'devis.png', 'image/png'), ['kind' => 'quote', 'plannedTaskId' => $foreignTask], expect: 422);
        $this->upload($browser, $vehicle, $this->tmpUpload(self::PNG, 'devis.png', 'image/png'), ['kind' => 'quote', 'plannedTaskId' => 'does-not-exist'], expect: 422);
    }

    public function testForeignOwnerGets404EverywhereAndSeesNothing(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $doc = $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'mr361.pdf', 'application/pdf'));
        $base = '/garage/vehicles/'.$vehicle->getId().'/documents';

        $this->upload($browser, $vehicle, $this->tmpUpload(PdfFixture::renaultManual(), 'x.pdf', 'application/pdf'), email: 'intruder@example.com', expect: 404);

        foreach ([['GET', $base], ['GET', $base.'/'.$doc['id'].'/file'], ['DELETE', $base.'/'.$doc['id']]] as [$method, $url]) {
            $browser->request($method, $url, [], [], $this->bearer('intruder@example.com'));
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $method.' '.$url);
        }

        $browser->request('GET', $base, [], [], $this->bearer());
        self::assertCount(1, json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testImportCommandIsIdempotentOnSourceRefAndFilename(): void
    {
        static::bootKernel();
        $vehicle = $this->seedVehicle();
        $path = sys_get_temp_dir().'/mr361.pdf';
        file_put_contents($path, PdfFixture::renaultManual());

        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:documentation:import'));
        $args = ['vehicle-id' => (string) $vehicle->getId(), 'path' => [$path], '--kind' => 'manual', '--source-ref' => 'MR 361 — Transmission'];

        self::assertSame(0, $tester->execute($args), $tester->getDisplay());
        self::assertSame(0, $tester->execute($args), $tester->getDisplay());

        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $collection = $dm->getDocumentCollection(VehicleDocument::class);
        $filter = ['vehicle' => (string) $vehicle->getId()];
        self::assertSame(1, $collection->countDocuments($filter));
        self::assertCount(2, $collection->findOne($filter)['pages']);

        $tester->execute(['--source-ref' => 'MR 362'] + $args);
        self::assertSame(2, $collection->countDocuments($filter));
    }
}
