<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheImportCommandTest extends AppTestCase
{
    public function testImportsLegacyDirIntoBothPools(): void
    {
        $tester = $this->runImport(\dirname(__DIR__).'/Fixtures/LegacyCacheImport');

        $this->assertSame(0, $tester->getStatusCode());

        $siv = self::sivCache()->getItem('BB456CC');
        $this->assertTrue($siv->isHit());
        $this->assertSame('RENAULT', $siv->get()['data']['marque']);

        $parts = self::partsApiCache();
        $data = $parts->getItem('getMakes.1111111111111111111111111111111111111111');
        $this->assertTrue($data->isHit());
        $this->assertSame([['makeId' => 93, 'makeName' => 'RENAULT']], $data->get());

        $counter = $parts->getItem('calls.getMakes');
        $this->assertTrue($counter->isHit());
        $this->assertSame(7, $counter->get());
    }

    public function testIsIdempotent(): void
    {
        $dir = \dirname(__DIR__).'/Fixtures/LegacyCacheImport';
        $this->runImport($dir);
        $this->runImport($dir);

        // A second run must not double-count the budget counter.
        $this->assertSame(7, self::partsApiCache()->getItem('calls.getMakes')->get());
    }

    private function runImport(string $dir): CommandTester
    {
        // Reuse the booted test kernel so the command writes through the same DAMA-wrapped
        // connection getContainer()/sivCache() read from — otherwise the rows would land in
        // a different transaction and be invisible to the assertions (and never rolled back).
        $application = new Application(static::bootKernel());
        $tester = new CommandTester($application->find('app:cache:import-legacy'));
        $tester->execute(['dir' => $dir]);

        return $tester;
    }
}
