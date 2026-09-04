<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Tests\Mock\RecordingMessageBus;
use App\Tests\Mock\TestDashboardChannel;
use App\Agent\Ui\StepNarrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class StepNarratorTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published filled with each published frame
     */
    private function narrator(\ArrayObject $published, Locale $locale = Locale::Fr): StepNarrator
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new RecordingMessageBus(), TestDashboardChannel::locale());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $context = new LocaleContext(new RequestStack());
        $context->set($locale);

        return new StepNarrator($tools, $context);
    }

    /**
     * The frame carries the tool key alongside the sentence. The dashboard used to recover the tool
     * by keyword-matching the French prose, which made the chips hostage to the wording — and to the
     * language. The producer already knows the key; it publishes it.
     */
    public function testNarratesAKnownGarageToolWithItsSentenceAndItsToolKey(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    /** Both history tools narrate the same sentence but stay distinguishable on the wire. */
    public function testTheTwoHistoryToolsShareASentenceButKeepTheirOwnToolKey(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);
        $narrator->forTool('get_vehicle_facts');
        $narrator->forTool('get_maintenance_history');

        $this->assertSame(
            [
                ['type' => 'status', 'message' => "Je relis l'historique…", 'tool' => 'get_vehicle_facts'],
                ['type' => 'status', 'message' => "Je relis l'historique…", 'tool' => 'get_maintenance_history'],
            ],
            $published->getArrayCopy(),
        );
    }

    public function testStaysSilentForAnUnmappedTool(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }

    public function testNarratesTheWebSearchStep(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('web_search');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je cherche en ligne…', 'tool' => 'web_search']],
            $published->getArrayCopy(),
        );
    }

    /**
     * A pilot narrates its own steps through `narrate_step` and has no tool key to offer. The field is
     * then absent rather than empty — the dashboard falls back to reading the sentence.
     */
    public function testAFreeNarrationCarriesNoToolKey(): void
    {
        $published = new \ArrayObject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new RecordingMessageBus(), TestDashboardChannel::locale());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->narrate('Je relis le carnet…');

        $this->assertSame([['type' => 'status', 'message' => 'Je relis le carnet…']], $published->getArrayCopy());
    }

    /**
     * The heartbeat follows the interface language, and the tool key on the frame does not move — the
     * dashboard chip stays correct in either language because it reads the key, never the prose.
     */
    public function testNarratesInEnglishWhenTheInterfaceIsEnglish(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published, Locale::En)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Checking the parts catalogue…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    public function testAnUnmappedToolIsSilentInEveryLanguage(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published, Locale::En)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }
}
