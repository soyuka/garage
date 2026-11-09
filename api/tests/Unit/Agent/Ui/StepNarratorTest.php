<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\Tests\Mock\TestDashboardChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class StepNarratorTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published filled with each published frame
     */
    private function narrator(\ArrayObject $published): StepNarrator
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return new StepNarrator($tools);
    }

    /** Owner decision 2026-11-09: the ticker carries only what the model chose to say. A tool step is the key alone. */
    public function testAKnownGarageToolPublishesItsToolKeyWithNoSentence(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('get_service_parts');

        $this->assertSame([['type' => 'status', 'tool' => 'get_service_parts']], $published->getArrayCopy());
    }

    public function testTheWebSearchStepIsAToolKeyToo(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('web_search');

        $this->assertSame([['type' => 'status', 'tool' => 'web_search']], $published->getArrayCopy());
    }

    public function testStaysSilentForAToolWithNoStepChip(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('some_tool_with_no_chip');

        $this->assertSame([], $published->getArrayCopy());
    }

    public function testAnnouncePublishesTheStepAndRemembersTheId(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-1', 'get_service_parts');

        $this->assertSame([['type' => 'status', 'tool' => 'get_service_parts']], $published->getArrayCopy());
        $this->assertTrue($narrator->wasAnnounced('call-1'));
    }

    public function testAnnounceStaysSilentForAToolWithNoStepChipButStillRemembersTheId(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-2', 'some_tool_with_no_chip');

        $this->assertSame([], $published->getArrayCopy());
        $this->assertTrue($narrator->wasAnnounced('call-2'));
    }

    public function testAnnounceStepNarratesTheModelAuthoredLineWithNoToolKey(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announceStep('call-3', 'Je compare les marques…');

        $this->assertSame([['type' => 'status', 'message' => 'Je compare les marques…']], $published->getArrayCopy());
        $this->assertTrue($narrator->wasAnnounced('call-3'));
    }

    public function testWasAnnouncedIsFalseForAnUnknownId(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();

        $this->assertFalse($this->narrator($published)->wasAnnounced('never-seen'));
    }

    public function testResetClearsTheAnnouncedMemory(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-4', 'get_service_parts');
        $narrator->reset();

        $this->assertFalse($narrator->wasAnnounced('call-4'));
    }
}
