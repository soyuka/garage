<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RenderIntentToolsTest extends TestCase
{
    public function testPublishFailureIsBestEffortAndDoesNotBreakTheTurn(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('Failed to send an update.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $tools = new RenderIntentTools($hub, $logger);
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('ok', $tools->toast('Entretien enregistré', 'ok'));
    }

    public function testPublishSerialisesTheTypedIntentToTheBoundSessionTopic(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->selectVehicle('/garage/vehicles/01J');

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(['type' => 'selectVehicle', 'id' => '/garage/vehicles/01J'], json_decode($published->getData(), true));
    }
}
