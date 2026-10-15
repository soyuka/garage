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

        $this->assertSame('Je consulte le catalogue de pièces…', $fr->narration['get_service_parts'][0]);
        $this->assertSame('Je cherche en ligne…', $fr->narration['web_search'][0]);
        $this->assertSame('Demande interrompue.', $fr->cancelled);
    }

    public function testEnglishIsAWholeCatalogNotAFallback(): void
    {
        $en = AgentMessages::for(Locale::En);

        $this->assertSame('Checking the parts catalogue…', $en->narration['get_service_parts'][0]);
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

    /**
     * A one-variant tool would never vary — the whole point of the catalog (operator feedback
     * 2026-09-05: the fixed heartbeat line reads as repetitive).
     */
    #[DataProvider('locales')]
    public function testEveryToolHasAtLeastThreeVariantsInBothLanguages(Locale $locale): void
    {
        foreach (AgentMessages::for($locale)->narration as $tool => $variants) {
            $this->assertGreaterThanOrEqual(3, \count($variants), $tool.' has fewer than 3 variants');
            $this->assertLessThanOrEqual(4, \count($variants), $tool.' has more than 4 variants');
        }
    }

    /**
     * `get_vehicle_facts` and `get_maintenance_history` used to share their one sentence — a
     * collision the operator flagged (facts is the spec sheet, history is the logbook). Pin it at
     * the catalog level, and generalise: no sentence should read as belonging to two different steps.
     */
    #[DataProvider('locales')]
    public function testNoTwoToolsShareAVariant(Locale $locale): void
    {
        $seen = [];
        foreach (AgentMessages::for($locale)->narration as $tool => $variants) {
            foreach ($variants as $sentence) {
                $this->assertArrayNotHasKey($sentence, $seen, \sprintf('"%s" is used by both %s and %s', $sentence, $seen[$sentence] ?? '?', $tool));
                $seen[$sentence] = $tool;
            }
        }
    }

    #[DataProvider('locales')]
    public function testEveryUserFacingSentenceIsNonEmpty(Locale $locale): void
    {
        $m = AgentMessages::for($locale);

        $this->assertNotSame('', trim($m->noOp));
        $this->assertNotSame('', trim($m->notConverged));
        $this->assertNotSame('', trim($m->cancelled));
        foreach ($m->narration as $tool => $variants) {
            foreach ($variants as $sentence) {
                $this->assertNotSame('', trim($sentence), $tool.' narrates an empty variant');
            }
        }
    }

    public function testTheFinderSpeaksLikeAPartsCounterNotLikeAnAssistant(): void
    {
        $fr = AgentMessages::for(Locale::Fr);

        $this->assertSame('Affinage des références…', $fr->finderNarration['show_parts']);
        $this->assertSame('Aucune pièce trouvée. Reformulez la demande ou choisissez une catégorie.', $fr->finderNoOp);
        foreach ([...array_values($fr->finderNarration), $fr->finderNoOp, $fr->finderNotConverged] as $sentence) {
            $this->assertDoesNotMatchRegularExpression("/\\b(Je|J'|ton|ta|tes|tu)\\b|Reformule\\b|choisis\\b/u", $sentence);
        }
    }

    public function testBothCatalogsNarrateTheSameFinderTools(): void
    {
        $fr = array_keys(AgentMessages::for(Locale::Fr)->finderNarration);
        $en = array_keys(AgentMessages::for(Locale::En)->finderNarration);
        sort($fr);
        sort($en);

        $this->assertSame($fr, $en);
        $this->assertNotSame('', trim(AgentMessages::for(Locale::En)->finderNotConverged));
    }

    /** @return iterable<string, array{Locale}> */
    public static function locales(): iterable
    {
        yield 'fr' => [Locale::Fr];
        yield 'en' => [Locale::En];
    }
}
