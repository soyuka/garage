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
    public function testFrenchAndEnglishAreWholeCatalogs(): void
    {
        $fr = AgentMessages::for(Locale::Fr);
        $en = AgentMessages::for(Locale::En);

        $this->assertSame('Liste des pièces d’entretien…', $fr->finderNarration['get_service_parts']);
        $this->assertSame('Recherche sur le web…', $fr->finderNarration['web_search']);
        $this->assertSame('Demande interrompue.', $fr->cancelled);
        $this->assertSame('Listing service parts…', $en->finderNarration['get_service_parts']);
        $this->assertSame('Request stopped.', $en->cancelled);
    }

    /** The narrator is keyed by tool name; every tool it knows must exist in both catalogs. */
    public function testTheNarratorOnlyNamesToolsBothCatalogsCover(): void
    {
        foreach (StepNarrator::TOOLS as $tool) {
            $this->assertArrayHasKey($tool, AgentMessages::for(Locale::Fr)->finderNarration);
            $this->assertArrayHasKey($tool, AgentMessages::for(Locale::En)->finderNarration);
        }
    }

    public function testTheNarratorNamesEveryToolTheCatalogsNarrate(): void
    {
        $this->assertEqualsCanonicalizing(StepNarrator::TOOLS, array_keys(AgentMessages::for(Locale::Fr)->finderNarration));
    }

    #[DataProvider('locales')]
    public function testEveryUserFacingSentenceIsNonEmpty(Locale $locale): void
    {
        $m = AgentMessages::for($locale);

        $this->assertNotSame('', trim($m->finderNoOp));
        $this->assertNotSame('', trim($m->finderNotConverged));
        $this->assertNotSame('', trim($m->cancelled));
        foreach ($m->finderNarration as $tool => $sentence) {
            $this->assertNotSame('', trim($sentence), $tool.' narrates an empty sentence');
        }
    }

    #[DataProvider('locales')]
    public function testNoTwoToolsShareASentence(Locale $locale): void
    {
        $sentences = array_values(AgentMessages::for($locale)->finderNarration);

        $this->assertSame($sentences, array_values(array_unique($sentences)));
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
