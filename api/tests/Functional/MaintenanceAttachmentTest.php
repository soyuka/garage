<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Bills attached to a logged visit. A maintenance record carries zero or more attachments
 * (PDF/JPEG/PNG), each stored in the S3-like filesystem. The dashboard logs the visit first, then
 * uploads its bills onto it (POST .../attachments), reads them back inlined on the record, streams
 * one for viewing (GET .../attachments/{id}) and removes one (DELETE). Owner-gated like the record.
 */
final class MaintenanceAttachmentTest extends AppTestCase
{
    private function seedVehicleIri(): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return '/garage/vehicles/'.(string) $vehicle->getId();
    }

    /** A logged visit's IRI (POST a bare record), ready to receive attachments. */
    private function seedRecordIri(): string
    {
        $client = static::createClient();
        $created = $client->request('POST', $this->seedVehicleIri().'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'performedAt' => '2025-09-12'],
        ])->toArray();

        return $created['@id'];
    }

    /** Write a temp file with the given bytes and wrap it as an UploadedFile for the kernel browser. */
    private function tmpUpload(string $bytes, string $clientName, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mpa_att_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, $clientMime, test: true);
    }

    private const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    public function testUploadThenRecordInlinesTheAttachment(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();

        $browser->request('POST', $recordIri.'/attachments', [], [
            'file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf'),
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);

        $this->assertSame(201, $browser->getResponse()->getStatusCode());
        $att = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('facture.pdf', $att['filename']);
        $this->assertSame('application/pdf', $att['contentType']);
        $this->assertSame(\strlen(self::PDF), $att['size']);
        $this->assertStringStartsWith($recordIri.'/attachments/', $att['url']);

        $record = $client->request('GET', $recordIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(1, $record['attachments']);
        $this->assertSame('facture.pdf', $record['attachments'][0]['filename']);
    }

    public function testDownloadStreamsTheStoredBytes(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();

        $browser->request('POST', $recordIri.'/attachments', [], [
            'file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf'),
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);
        $url = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'];

        $browser->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);
        $res = $browser->getResponse();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('sandbox', $res->headers->get('Content-Security-Policy'), 'an uploaded file must never run script on our origin');
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame(self::PDF, $browser->getInternalResponse()->getContent());
    }

    public function testABillNamedWithAPercentSignStillDownloads(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];

        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture 100% réglée.pdf', 'application/pdf')], $auth);
        $url = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'];

        $browser->request('GET', $url, [], [], $auth);
        $this->assertSame(200, $browser->getResponse()->getStatusCode());
        $this->assertSame(
            'inline; filename="facture 100_ r__gl__e.pdf"; filename*=utf-8\'\'facture%20100%25%20r%C3%A9gl%C3%A9e.pdf',
            $browser->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testMultipleBillsOnOneVisit(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];

        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $auth);
        $this->assertSame(201, $browser->getResponse()->getStatusCode());
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PNG, 'ticket.png', 'image/png')], $auth);
        $this->assertSame(201, $browser->getResponse()->getStatusCode());

        $record = $client->request('GET', $recordIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(2, $record['attachments']);
    }

    public function testAFailedDeleteKeepsTheBill(): void
    {
        $recordIri = $this->seedRecordIri();
        $browser = static::getClient();
        $this->assertInstanceOf(KernelBrowser::class, $browser);
        $browser->disableReboot();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $auth);
        $url = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'];

        $events = static::getContainer()->get(DocumentManager::class)->getEventManager();
        $failing = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('the database went away');
            }
        };
        $events->addEventListener(Events::onFlush, $failing);
        $browser->catchExceptions(true);
        $browser->request('DELETE', $url, [], [], $auth);
        $this->assertSame(500, $browser->getResponse()->getStatusCode());
        $events->removeEventListener(Events::onFlush, $failing);

        $browser->request('GET', $url, [], [], $auth);
        $this->assertSame(200, $browser->getResponse()->getStatusCode(), 'the record still lists the bill, so its bytes must still be there');
    }

    public function testDeletingTheVisitDeletesItsBills(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $auth);
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PNG, 'ticket.png', 'image/png')], $auth);
        $recordId = basename($recordIri);
        $this->assertCount(2, $this->storedBills($recordId));

        $browser->request('DELETE', $recordIri, [], [], $auth);

        $this->assertSame(204, $browser->getResponse()->getStatusCode());
        $this->assertSame([], $this->storedBills($recordId));
    }

    public function testAFailedUploadLeavesNoBlob(): void
    {
        $recordIri = $this->seedRecordIri();
        $browser = static::getClient();
        $this->assertInstanceOf(KernelBrowser::class, $browser);
        $browser->disableReboot();
        $browser->catchExceptions(true);
        $events = static::getContainer()->get(DocumentManager::class)->getEventManager();
        $failing = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('the database went away');
            }
        };
        $events->addEventListener(Events::onFlush, $failing);

        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);
        $events->removeEventListener(Events::onFlush, $failing);

        $this->assertSame(500, $browser->getResponse()->getStatusCode());
        $this->assertSame([], $this->storedBills(basename($recordIri)));
    }

    /** @return list<string> */
    private function storedBills(string $recordId): array
    {
        $storage = static::getContainer()->get('maintenance.storage');
        $this->assertInstanceOf(FilesystemOperator::class, $storage);

        return array_values($storage->listContents($recordId)->map(static fn ($item): string => $item->path())->toArray());
    }

    public function testDeleteRemovesOneBill(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];

        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $auth);
        $url = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'];

        $browser->request('DELETE', $url, [], [], $auth);
        $this->assertSame(204, $browser->getResponse()->getStatusCode());

        $record = $client->request('GET', $recordIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $record['attachments']);
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();

        $browser->request('POST', $recordIri.'/attachments', [], [
            'file' => $this->tmpUpload("just some text\n", 'notes.txt', 'text/plain'),
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);

        $this->assertSame(422, $browser->getResponse()->getStatusCode());
    }

    public function testUploadRequiresAFile(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();

        $browser->request('POST', $recordIri.'/attachments', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')]);

        $this->assertSame(422, $browser->getResponse()->getStatusCode());
    }

    public function testForeignOwnerCannotUploadDownloadOrDelete(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $recordIri = $this->seedRecordIri();
        $owner = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, 'dashboard@example.com')];
        $intruder = ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for('intruder', 'intruder@example.com')];

        // Intruder cannot upload onto someone else's visit.
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $intruder);
        $this->assertSame(404, $browser->getResponse()->getStatusCode());

        // Owner uploads; intruder can neither download nor delete it.
        $browser->request('POST', $recordIri.'/attachments', [], ['file' => $this->tmpUpload(self::PDF, 'facture.pdf', 'application/pdf')], $owner);
        $url = json_decode($browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'];

        $browser->request('GET', $url, [], [], $intruder);
        $this->assertSame(404, $browser->getResponse()->getStatusCode());

        $browser->request('DELETE', $url, [], [], $intruder);
        $this->assertSame(404, $browser->getResponse()->getStatusCode());
    }
}
