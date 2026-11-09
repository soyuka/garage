<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\EarlyStepNarrationListener;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\I18n\LocaleContext;
use App\Tests\Mock\TestDashboardChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\HttpFoundation\RequestStack;
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
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $narrator = new StepNarrator($tools, new LocaleContext(new RequestStack()));

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
            [['type' => 'status', 'message' => 'Liste des pièces d’entretien…', 'tool' => 'get_service_parts']],
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
}
