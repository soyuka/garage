<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\I18n\Locale;
use App\I18n\LocaleContext;
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

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
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
    public function testNarratesAKnownToolWithItsSentenceAndItsToolKey(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Liste des pièces d’entretien…', 'tool' => 'get_service_parts']],
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
            [['type' => 'status', 'message' => 'Recherche sur le web…', 'tool' => 'web_search']],
            $published->getArrayCopy(),
        );
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
            [['type' => 'status', 'message' => 'Listing service parts…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    public function testAnUnmappedToolIsSilentInEveryLanguage(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published, Locale::En)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }

    /** announce() is forTool()'s early twin: same sentence, but the id is remembered as announced. */
    public function testAnnounceNarratesAMappedToolAndRemembersTheId(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-1', 'get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Liste des pièces d’entretien…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
        $this->assertTrue($narrator->wasAnnounced('call-1'));
    }

    /** An unmapped tool stays silent, exactly as forTool(), but the id is still marked announced. */
    public function testAnnounceStaysSilentForAnUnmappedToolButStillRemembersTheId(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-2', 'some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
        $this->assertTrue($narrator->wasAnnounced('call-2'));
    }

    public function testWasAnnouncedIsFalseForAnUnknownId(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $this->assertFalse($narrator->wasAnnounced('never-seen'));
    }

    /** reset() clears the per-turn memory — a stale worker singleton must not leak across turns. */
    public function testResetClearsTheAnnouncedMemory(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);

        $narrator->announce('call-4', 'get_service_parts');
        $narrator->reset();

        $this->assertFalse($narrator->wasAnnounced('call-4'));
    }

    public function testResetKeepsNarratingTheSameSentencePerTool(): void
    {
        $published = new \ArrayObject();
        $narrator = $this->narrator($published);
        $narrator->forTool('show_parts');
        $narrator->forTool('list_cars');
        $narrator->reset();
        $narrator->forTool('show_parts');

        $this->assertSame(
            [
                ['type' => 'status', 'message' => 'Affinage des références…', 'tool' => 'show_parts'],
                ['type' => 'status', 'message' => 'Recherche de la motorisation…', 'tool' => 'list_cars'],
                ['type' => 'status', 'message' => 'Affinage des références…', 'tool' => 'show_parts'],
            ],
            $published->getArrayCopy(),
        );
    }

    public function testEveryNarratedToolHasASentenceInBothLanguages(): void
    {
        foreach ([Locale::Fr, Locale::En] as $locale) {
            $published = new \ArrayObject();
            $narrator = $this->narrator($published, $locale);
            foreach (StepNarrator::TOOLS as $tool) {
                $narrator->forTool($tool);
            }

            $this->assertCount(\count(StepNarrator::TOOLS), $published, $locale->value);
        }
    }
}
