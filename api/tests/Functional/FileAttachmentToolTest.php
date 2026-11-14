<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Attachment\TurnAttachments;
use App\Agent\Attachment\TurnAttachmentScope;
use App\Entity\MaintenanceRecord;
use App\Entity\TurnAttachment;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Garage\VehicleType;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `file_attachment` is the agent's way to keep what the user attached to a turn: as a vehicle
 * document (through the shared recorder, text extraction included) or as a bill on a logged visit.
 * It is an in-app tool only: the public MCP server has no turn attachments.
 */
final class FileAttachmentToolTest extends AppTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    public function testFilesAnAttachmentAsAVehicleDocument(): void
    {
        static::createClient();
        $vehicle = $this->seedVehicle(self::TEST_OWNER);
        $id = $this->bindTurnWithAttachment(self::TEST_OWNER, PdfFixture::renaultManual(), 'notice.pdf');

        $result = $this->call(['attachmentId' => $id, 'as' => 'vehicle_document', 'vehicle' => '/garage/vehicles/'.(string) $vehicle->getId(), 'kind' => 'technical_note', 'title' => 'Note boîte']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('technical_note', $result['document']['kind']);
        $this->assertSame('Note boîte', $result['document']['title']);
        $this->assertGreaterThan(0, $result['document']['pageCount'], 'a PDF is text-extracted by the recorder');

        $dm = static::getContainer()->get(DocumentManager::class);
        $this->assertCount(1, $dm->getRepository(VehicleDocument::class)->findAll());
        $this->assertNull(static::getContainer()->get(TurnAttachments::class)->find(self::TEST_OWNER, $id), 'filing consumes the temp file');
        $this->assertSame([], $dm->getRepository(TurnAttachment::class)->findAll());
    }

    public function testFilesAnAttachmentAsABillOnAVisit(): void
    {
        static::createClient();
        $vehicle = $this->seedVehicle(self::TEST_OWNER);
        $record = $this->seedRecord($vehicle);
        $id = $this->bindTurnWithAttachment(self::TEST_OWNER, self::PNG, 'facture.png');

        $result = $this->call(['attachmentId' => $id, 'as' => 'bill', 'record' => '/garage/maintenance-records/'.(string) $record->getId()]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('facture.png', $result['attachment']['filename']);
        $this->assertSame('image/png', $result['attachment']['contentType']);

        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();
        $this->assertCount(1, $dm->find(MaintenanceRecord::class, (string) $record->getId())->getAttachments());
    }

    public function testAnotherOwnersVehicleAndRecordAreNotFound(): void
    {
        static::createClient();
        $foreign = $this->seedVehicle('mallory@example.com');
        $foreignRecord = $this->seedRecord($foreign);
        $id = $this->bindTurnWithAttachment(self::TEST_OWNER, self::PNG, 'a.png');

        $doc = $this->call(['attachmentId' => $id, 'as' => 'vehicle_document', 'vehicle' => '/garage/vehicles/'.(string) $foreign->getId()]);
        $bill = $this->call(['attachmentId' => $id, 'as' => 'bill', 'record' => '/garage/maintenance-records/'.(string) $foreignRecord->getId()]);

        $this->assertSame('not_found', $doc['status']);
        $this->assertSame('not_found', $bill['status']);
        $this->assertNotNull(static::getContainer()->get(TurnAttachments::class)->find(self::TEST_OWNER, $id), 'a refused filing keeps the temp file');
    }

    public function testAnAttachmentOfAnotherTurnOrOwnerCannotBeFiled(): void
    {
        static::createClient();
        $vehicle = $this->seedVehicle(self::TEST_OWNER);
        $others = static::getContainer()->get(TurnAttachments::class)->stash('mallory@example.com', $this->upload(self::PNG, 'x.png'), 'image/png');
        $this->bindTurnWithAttachment(self::TEST_OWNER, self::PNG, 'mine.png');

        $result = $this->call(['attachmentId' => $others->id, 'as' => 'vehicle_document', 'vehicle' => '/garage/vehicles/'.(string) $vehicle->getId()]);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], static::getContainer()->get(DocumentManager::class)->getRepository(VehicleDocument::class)->findAll());
    }

    public function testAnExpiredAttachmentCannotBeFiled(): void
    {
        static::createClient();
        $vehicle = $this->seedVehicle(self::TEST_OWNER);
        $id = $this->bindTurnWithAttachment(self::TEST_OWNER, self::PNG, 'a.png');
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->createQueryBuilder(TurnAttachment::class)->updateOne()->field('id')->equals($id)
            ->field('expiresAt')->set(new \DateTimeImmutable('-1 minute'))->getQuery()->execute();
        $dm->clear();

        $result = $this->call(['attachmentId' => $id, 'as' => 'vehicle_document', 'vehicle' => '/garage/vehicles/'.(string) $vehicle->getId()]);

        $this->assertSame('not_found', $result['status']);
    }

    public function testMissingTargetIsInvalidInput(): void
    {
        static::createClient();
        $id = $this->bindTurnWithAttachment(self::TEST_OWNER, self::PNG, 'a.png');

        $this->assertSame('invalid_input', $this->call(['attachmentId' => $id, 'as' => 'vehicle_document'])['status']);
        $this->assertSame('invalid_input', $this->call(['attachmentId' => $id, 'as' => 'bill'])['status']);
        $this->assertStringContainsString('not a valid choice', $this->call(['attachmentId' => $id, 'as' => 'nope'])['reason'], 'the schema enum refuses it before the tool runs');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(array $arguments): array
    {
        $toolbox = self::serviceAs('ai.toolbox.garage', ToolboxInterface::class);
        $result = $toolbox->execute(new ToolCall('f1', 'file_attachment', $arguments))->getResult();

        return \is_array($result) ? $result : (json_decode((string) $result, true) ?? ['status' => 'refused', 'reason' => (string) $result]);
    }

    private function bindTurnWithAttachment(string $owner, string $bytes, string $name): string
    {
        $container = static::getContainer();
        $ref = $container->get(TurnAttachments::class)->stash($owner, $this->upload($bytes, $name), (string) (new \Symfony\Component\Mime\MimeTypes())->guessMimeType($this->upload($bytes, $name)->getPathname()));
        $container->get(OwnerContext::class)->set($owner);
        $container->get(TurnAttachmentScope::class)->bind([$ref]);

        return $ref->id;
    }

    private function seedVehicle(string $owner): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'ESPACE', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner($owner);
        $dm->persist($vehicle);
        $dm->flush();

        return $vehicle;
    }

    private function seedRecord(Vehicle $vehicle): MaintenanceRecord
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $record = new MaintenanceRecord($vehicle, [['type' => 'oil_change', 'parts' => []]], new \DateTimeImmutable('2026-01-01'), 'user');
        $dm->persist($record);
        $dm->flush();

        return $record;
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mpa_file_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, test: true);
    }
}
