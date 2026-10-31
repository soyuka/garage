<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Storage\MaintenanceAttachmentStorage;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StorageAdapterTest extends KernelTestCase
{
    private string $adapter;

    protected function setUp(): void
    {
        $this->adapter = $_SERVER['STORAGE_ADAPTER'];
    }

    protected function tearDown(): void
    {
        $_SERVER['STORAGE_ADAPTER'] = $_ENV['STORAGE_ADAPTER'] = $this->adapter;
        putenv('STORAGE_ADAPTER='.$this->adapter);
        parent::tearDown();
    }

    public function testDefaultAdapterIsLocalAndRoundTripsABill(): void
    {
        self::bootKernel();
        $storage = self::getContainer()->get(MaintenanceAttachmentStorage::class);
        \assert($storage instanceof MaintenanceAttachmentStorage);

        $key = $storage->store('record-1', 'attachment-1', 'bill-bytes');

        self::assertSame('bill-bytes', stream_get_contents($storage->readStream($key)));
        self::assertSame(
            self::getContainer()->get('maintenance.local'),
            self::getContainer()->get('maintenance.storage'),
        );

        $storage->delete($key);
    }

    public function testDefaultAdapterIsLocalForVehicleDocuments(): void
    {
        self::bootKernel();

        self::assertSame(
            self::getContainer()->get('vehicle_documents.local'),
            self::getContainer()->get('vehicle_documents.storage'),
        );
    }

    public function testS3AdapterIsSelectedByEnv(): void
    {
        $_SERVER['STORAGE_ADAPTER'] = $_ENV['STORAGE_ADAPTER'] = 's3';
        putenv('STORAGE_ADAPTER=s3');

        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(FilesystemOperator::class, $container->get('maintenance.storage'));
        self::assertSame($container->get('maintenance.s3'), $container->get('maintenance.storage'));
        self::assertSame($container->get('vehicle_documents.s3'), $container->get('vehicle_documents.storage'));
        self::assertNotSame($container->get('maintenance.local'), $container->get('maintenance.storage'));
    }
}
