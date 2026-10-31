<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Tests\Mock\TestDashboardChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class StepNarratorTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published filled with each published frame
     * @param \Closure(int $count): int|null          $picker    defaults to always picking the first
     *                                                           variant, so tests that assert an exact
     *                                                           sentence stay deterministic without
     *                                                           caring about the randomised default
     */
    private function narrator(\ArrayObject $published, Locale $locale = Locale::Fr, ?\Closure $picker = null): StepNarrator
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $context = new LocaleContext(new RequestStack());
        $context->set($locale);

        return new StepNarrator($tools, $context, $picker ?? static fn (int $n): int => 0);
    }

    /**
     * The frame carries the tool key alongside the sentence. The dashboard used to recover the tool
     * by keyword-matching the French prose, which made the chips hostage to the wording — and to the
     * language. The producer already knows the key; it publishes it.
     */
    public function testNarratesAKnownGarageToolWithItsSentenceAndItsToolKey(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    /**
     * `get_vehicle_facts` (the spec sheet) and `get_maintenance_history` (the logbook) used to share
     * the exact same sentence — an operator-flagged collision. They now narrate distinct wording, and
     * still keep their own tool key on the wire.
     */
    public function testFactsAndHistoryNarrateDifferentSentencesAndKeepTheirOwnToolKey(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);
        $narrator->forTool('get_vehicle_facts');
        $narrator->forTool('get_maintenance_history');

        $this->assertSame(
            [
                ['type' => 'status', 'message' => 'Je relis la fiche technique…', 'tool' => 'get_vehicle_facts'],
                ['type' => 'status', 'message' => "Je relis l'historique…", 'tool' => 'get_maintenance_history'],
            ],
            $published->getArrayCopy(),
        );
    }

    /** With a picker that returns a fixed sequence, the same tool called twice varies its wording. */
    public function testForToolVariesTheWordingAcrossTwoCallsWithAFixedSequencePicker(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $sequence = [0, 1];
        $picker = static function (int $n) use (&$sequence): int {
            return array_shift($sequence);
        };
        $narrator = $this->narrator($published, picker: $picker);

        $narrator->forTool('get_maintenance_history');
        $narrator->forTool('get_maintenance_history');

        $this->assertSame(
            [
                ['type' => 'status', 'message' => "Je relis l'historique…", 'tool' => 'get_maintenance_history'],
                ['type' => 'status', 'message' => 'Je reprends le carnet…', 'tool' => 'get_maintenance_history'],
            ],
            $published->getArrayCopy(),
        );
    }

    /**
     * A picker that keeps returning the same index must still never narrate the same wording twice
     * in a row — the narrator shifts the pick itself rather than trusting the picker's variety.
     */
    public function testForToolNeverRepeatsTheSameVariantEvenWhenThePickerAlwaysReturnsTheSameIndex(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published, picker: static fn (int $n): int => 0);

        $narrator->forTool('get_maintenance_history');
        $narrator->forTool('get_maintenance_history');
        $narrator->forTool('get_maintenance_history');

        $messages = array_map(static fn (array $frame): string => $frame['message'], $published->getArrayCopy());

        $this->assertNotSame($messages[0], $messages[1]);
        $this->assertNotSame($messages[1], $messages[2]);
    }

    /** reset() clears the per-tool variant memory too — a stale worker singleton starts fresh each turn. */
    public function testResetClearsTheVariantMemorySoTheFirstVariantCanRepeatOnTheNextTurn(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published, picker: static fn (int $n): int => 0);

        $narrator->forTool('get_maintenance_history');
        $narrator->reset();
        $narrator->forTool('get_maintenance_history');

        $messages = array_map(static fn (array $frame): string => $frame['message'], $published->getArrayCopy());

        $this->assertSame($messages[0], $messages[1]);
    }

    public function testStaysSilentForAnUnmappedTool(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }

    public function testNarratesTheWebSearchStep(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
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
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
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
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published, Locale::En)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Checking the parts catalogue…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    public function testAnUnmappedToolIsSilentInEveryLanguage(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $this->narrator($published, Locale::En)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }

    /** announce() is forTool()'s early twin: same sentence, but the id is remembered as announced. */
    public function testAnnounceNarratesAMappedToolAndRemembersTheId(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-1', 'get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
        $this->assertTrue($narrator->wasAnnounced('call-1'));
    }

    /** An unmapped tool stays silent, exactly as forTool(), but the id is still marked announced. */
    public function testAnnounceStaysSilentForAnUnmappedToolButStillRemembersTheId(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $published */
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-2', 'some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
        $this->assertTrue($narrator->wasAnnounced('call-2'));
    }

    /** announceStep() carries the model's own sentence, with no tool key — narrate_step's shape. */
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
        $narrator = $this->narrator($published);

        $this->assertFalse($narrator->wasAnnounced('never-seen'));
    }

    /** reset() clears the per-turn memory — a stale worker singleton must not leak across turns. */
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
