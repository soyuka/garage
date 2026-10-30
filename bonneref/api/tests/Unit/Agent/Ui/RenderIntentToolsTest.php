<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\TestDashboardChannel;
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

        $tools = new RenderIntentTools($hub, $logger, TestDashboardChannel::create('https://localhost/agent/sessions/abc'));

        $tools->publishFrame(['type' => 'status', 'message' => 'x']);
    }

    public function testPublishSerialisesTheFrameToTheBoundSessionTopic(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->publishFrame(['type' => 'showParts', 'id' => 'p1']);

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(['type' => 'showParts', 'id' => 'p1'], json_decode($published->getData(), true));
    }

    public function testBoundTurnPublishesOnlyItsLatestToastImmediatelyBeforeItEnds(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->signalTurnPhase('start');
        $tools->toast('Je regarde les pièces.');
        $tools->publishFrame(['type' => 'showParts', 'id' => 'p1']);
        $tools->toast('Trois références conviennent.', 'ok');
        $tools->signalTurnPhase('end');

        $this->assertSame([
            ['type' => 'turn', 'phase' => 'start'],
            ['type' => 'showParts', 'id' => 'p1'],
            ['type' => 'toast', 'tone' => 'ok', 'message' => 'Trois références conviennent.'],
            ['type' => 'turn', 'phase' => 'end'],
        ], $published);
    }

    public function testAFailedTurnEndCarriesTheOutcome(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create('https://localhost/agent/sessions/abc'));

        $tools->signalTurnPhase('end', failed: true);

        $this->assertSame([['type' => 'turn', 'phase' => 'end', 'outcome' => 'failed']], $published);
    }

    public function testNarratePublishesATransientStatusFrameSeparateFromToast(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->narrate('Lecture de la plaque…');

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(
            ['type' => 'status', 'message' => 'Lecture de la plaque…'],
            json_decode($published->getData(), true),
        );
    }

    public function testNarrateCarriesTheToolKeyWhenGiven(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create('https://localhost/agent/sessions/abc'));

        $tools->narrate('Lecture de la plaque…', 'lookup_plate');

        $this->assertSame(
            ['type' => 'status', 'message' => 'Lecture de la plaque…', 'tool' => 'lookup_plate'],
            json_decode($published->getData(), true),
        );
    }
}
