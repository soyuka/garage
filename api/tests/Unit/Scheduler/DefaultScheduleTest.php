<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DefaultScheduleTest extends KernelTestCase
{
    public function testPrunesTurnAttachmentsEveryHour(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('debug:scheduler'));
        $tester->execute(['schedule' => ['default']]);

        $tester->assertCommandIsSuccessful();
        $this->assertMatchesRegularExpression('/every 1 hour .*app:turn-attachments:prune/', $tester->getDisplay());
    }
}
