<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\Agent\Ui\StepNarrator;
use App\I18n\AgentMessages;
use App\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentMessagesTest extends TestCase
{
    public function testFrenchKeepsTheSentencesTheAtelierAlreadySpoke(): void
    {
        $fr = AgentMessages::for(Locale::Fr);

        $this->assertSame('Je consulte le catalogue de pièces…', $fr->narration['get_service_parts']);
        $this->assertSame('Je cherche en ligne…', $fr->narration['web_search']);
        $this->assertSame('Demande interrompue.', $fr->cancelled);
    }

    public function testEnglishIsAWholeCatalogNotAFallback(): void
    {
        $en = AgentMessages::for(Locale::En);

        $this->assertSame('Checking the parts catalogue…', $en->narration['get_service_parts']);
        $this->assertSame('Request stopped.', $en->cancelled);
    }

    /**
     * A tool narrating in one language and silent in the other would read as a dropped step, not as a
     * missing translation — the failure is invisible. Pin the key sets instead of the words.
     */
    public function testBothCatalogsNarrateExactlyTheSameTools(): void
    {
        $fr = array_keys(AgentMessages::for(Locale::Fr)->narration);
        $en = array_keys(AgentMessages::for(Locale::En)->narration);
        sort($fr);
        sort($en);

        $this->assertSame($fr, $en);
    }

    /** The narrator is keyed by tool name; every tool it knows must exist in both catalogs. */
    public function testTheNarratorOnlyNamesToolsBothCatalogsCover(): void
    {
        foreach (StepNarrator::TOOLS as $tool) {
            $this->assertArrayHasKey($tool, AgentMessages::for(Locale::Fr)->narration);
            $this->assertArrayHasKey($tool, AgentMessages::for(Locale::En)->narration);
        }
    }

    #[DataProvider('locales')]
    public function testEveryUserFacingSentenceIsNonEmpty(Locale $locale): void
    {
        $m = AgentMessages::for($locale);

        $this->assertNotSame('', trim($m->noOp));
        $this->assertNotSame('', trim($m->notConverged));
        $this->assertNotSame('', trim($m->cancelled));
        foreach ($m->narration as $tool => $sentence) {
            $this->assertNotSame('', trim($sentence), $tool.' narrates nothing');
        }
    }

    /** @return iterable<string, array{Locale}> */
    public static function locales(): iterable
    {
        yield 'fr' => [Locale::Fr];
        yield 'en' => [Locale::En];
    }
}
