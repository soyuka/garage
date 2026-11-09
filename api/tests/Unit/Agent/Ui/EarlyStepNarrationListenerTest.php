<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\EarlyStepNarrationListener;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\Tests\Mock\TestDashboardChannel;
use App\Tests\Mock\TestRenderTargets;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class EarlyStepNarrationListenerTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published filled with each published frame
     */
    private function listener(\ArrayObject $published): EarlyStepNarrationListener
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $narrator = new StepNarrator($tools);

        return new EarlyStepNarrationListener($narrator);
    }

    private function eventFor(DeltaInterface $delta): DeltaEvent
    {
        $empty = (static function (): \Generator { yield from []; })();

        return new DeltaEvent(new StreamResult($empty), $delta);
    }

    public function testToolCallStartForAMappedToolNarratesOnceAndMarksItAnnounced(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $listener = $this->listener($published);

        $listener->onDelta($this->eventFor(new ToolCallStart('call-1', 'get_service_parts')));

        $this->assertSame(
            [['type' => 'status', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    public function testToolCallStartForAnUnmappedToolNarratesNothingButStillAnnounces(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $listener = $this->listener($published);

        $listener->onDelta($this->eventFor(new ToolCallStart('call-2', 'some_tool_with_no_sentence')));

        $this->assertSame([], $published->getArrayCopy());
    }

    /** narrate_step's args arrive split across two ToolInputDelta pieces; publish happens once, after the second. */
    public function testNarrateStepPublishesOnceAfterItsArgumentsCompleteAcrossTwoDeltas(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $listener = $this->listener($published);

        $listener->onDelta($this->eventFor(new ToolCallStart('call-3', 'narrate_step')));
        $this->assertSame([], $published->getArrayCopy(), 'narrate_step must not narrate its tool name at start');

        $listener->onDelta($this->eventFor(new ToolInputDelta('call-3', 'narrate_step', '{"mess')));
        $this->assertSame([], $published->getArrayCopy(), 'a partial JSON must not publish');

        $listener->onDelta($this->eventFor(new ToolInputDelta('call-3', 'narrate_step', 'age": "Je compare les marques…"}')));
        $this->assertSame(
            [['type' => 'status', 'message' => 'Je compare les marques…']],
            $published->getArrayCopy(),
        );
    }

    public function testNarrateStepWhoseJsonNeverCompletesPublishesNothing(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $listener = $this->listener($published);

        $listener->onDelta($this->eventFor(new ToolCallStart('call-4', 'narrate_step')));
        $listener->onDelta($this->eventFor(new ToolInputDelta('call-4', 'narrate_step', '{"message": "Je c')));

        $this->assertSame([], $published->getArrayCopy());
    }
}
