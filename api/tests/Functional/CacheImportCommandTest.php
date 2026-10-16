<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PartsApiResponse;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheImportCommandTest extends AppTestCase
{
    public function testImportsLegacyDirIntoBothStores(): void
    {
        $tester = $this->runImport(\dirname(__DIR__).'/Fixtures/LegacyCacheImport');

        $this->assertSame(0, $tester->getStatusCode());

        $this->assertSame('RENAULT', self::sivStore()->find('BB456CC')['data']['marque'] ?? null);
        $this->assertSame([['makeId' => 93, 'makeName' => 'RENAULT']], self::partsApiStore()->find('getMakes.1111111111111111111111111111111111111111'));

        // The call ceiling those counters fed is gone (fff1cf9): reported, never stored.
        $this->assertStringContainsString('ignored 1 dead call counter', $tester->getDisplay());
        $this->assertNull(self::partsApiStore()->find('calls.getMakes'));
    }

    public function testIsIdempotent(): void
    {
        $dir = \dirname(__DIR__).'/Fixtures/LegacyCacheImport';
        $this->runImport($dir);
        $tester = $this->runImport($dir);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, static::getContainer()->get(DocumentManager::class)->getDocumentCollection(PartsApiResponse::class)->countDocuments());
    }

    private function runImport(string $dir): CommandTester
    {
        $application = new Application(static::bootKernel());
        $tester = new CommandTester($application->find('app:cache:import-legacy'));
        $tester->execute(['dir' => $dir]);

        return $tester;
    }
}
