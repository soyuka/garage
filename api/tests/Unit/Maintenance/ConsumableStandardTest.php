<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Maintenance\ConsumableStandard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsumableStandardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recognised(): iterable
    {
        yield 'dot4 lower' => ['dot4', 'DOT 4'];
        yield 'dot hyphen' => ['DOT-4', 'DOT 4'];
        yield 'dot spaced' => ['  dot  4 ', 'DOT 4'];
        yield 'dot3' => ['DOT3', 'DOT 3'];
        yield 'dot 5.1' => ['dot5.1', 'DOT 5.1'];
        yield 'dot 5.1 comma' => ['DOT 5,1', 'DOT 5.1'];
        yield 'sae bare' => ['5w30', 'SAE 5W-30'];
        yield 'sae hyphen' => ['5W-30', 'SAE 5W-30'];
        yield 'sae prefixed' => ['SAE 5W30', 'SAE 5W-30'];
        yield 'sae gear' => ['75w80', 'SAE 75W-80'];
        yield 'sae already canonical' => ['SAE 75W-80', 'SAE 75W-80'];
        yield 'sae two digit low' => ['10w-40', 'SAE 10W-40'];
        yield 'sae mono' => ['SAE 80W', 'SAE 80W'];
        yield 'sae mono lower' => ['sae 80w', 'SAE 80W'];
        yield 'dexron ii' => ['atf dexron ii d', 'ATF Dexron II D'];
        yield 'dexron iid' => ['Dexron IID', 'ATF Dexron II D'];
        yield 'dexron iii' => ['dexron-III', 'ATF Dexron III'];
        yield 'threadlocker medium' => ['Frein filet moyen', 'frein-filet moyen'];
        yield 'threadlocker medium loctite' => ['loctite 243', 'frein-filet moyen'];
        yield 'threadlocker strong' => ['FREIN-FILET FORT', 'frein-filet fort'];
        yield 'threadlocker strong loctite' => ['Loctite 270', 'frein-filet fort'];
        yield 'grease' => ['graisse li-mos2 nlgi 2', 'graisse Li-MoS2 NLGI 2'];
        yield 'silicone' => ['Silicone RTV haute temperature', 'silicone RTV haute température'];
    }

    #[DataProvider('recognised')]
    public function testNormalisesRecognisedConsumables(string $input, string $canonical): void
    {
        $this->assertSame($canonical, ConsumableStandard::normalise($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'manufacturer ref' => ['623 3150 33'];
        yield 'filter ref' => ['W 712/75'];
        yield 'oem' => ['OEM-DRAIN-GASKET'];
        yield 'unknown' => ['liquide spécial'];
        yield 'dot-like' => ['DOT 4 PLUS'];
        yield 'sae-like part' => ['5W30 ABC-123'];
        yield 'empty' => [''];
        yield 'padded unknown' => ['  W 712/75 '];
    }

    #[DataProvider('untouched')]
    public function testPassesEverythingElseThroughUnchanged(string $input): void
    {
        $this->assertSame($input, ConsumableStandard::normalise($input));
    }

    public function testNormaliseIsIdempotentOnEveryCanonical(): void
    {
        foreach (ConsumableStandard::canonicals() as $canonical) {
            $this->assertSame($canonical, ConsumableStandard::normalise($canonical));
        }
    }

    public function testDescribesTheVocabularyForSchemas(): void
    {
        $text = ConsumableStandard::describe();

        $this->assertStringContainsString('DOT 4', $text);
        $this->assertStringContainsString('SAE 5W-30', $text);
        $this->assertStringContainsString('frein-filet fort', $text);
    }

    /**
     * @return iterable<string, array{string, ?string, string, ?string}>
     */
    public static function labelCases(): iterable
    {
        yield 'product, empty label' => ['LOCTITE-243', null, 'frein-filet moyen', 'LOCTITE-243'];
        yield 'product, blank label' => ['Loctite 270', '  ', 'frein-filet fort', 'Loctite 270'];
        yield 'product, existing label' => ['LOCTITE-243', 'Frein filet', 'frein-filet moyen', 'Frein filet (LOCTITE-243)'];
        yield 'product already in label' => ['loctite 243', 'Loctite 243 bleu', 'frein-filet moyen', 'Loctite 243 bleu'];
        yield 'spelling variant leaves label' => ['DOT4', null, 'DOT 4', null];
        yield 'spelling variant keeps label' => ['75W-80', 'Huile de pont', 'SAE 75W-80', 'Huile de pont'];
        yield 'alias variant leaves label' => ['dexron iid', null, 'ATF Dexron II D', null];
        yield 'unknown leaves label' => ['W 712/75', 'Filtre', 'W 712/75', 'Filtre'];
    }

    #[DataProvider('labelCases')]
    public function testResolveKeepsProductNamesOnTheLabelOnly(string $ref, ?string $label, string $expectedRef, ?string $expectedLabel): void
    {
        $this->assertSame([$expectedRef, $expectedLabel], ConsumableStandard::resolve($ref, $label));
    }
}
