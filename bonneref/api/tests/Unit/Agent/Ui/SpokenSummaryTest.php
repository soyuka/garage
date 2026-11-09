<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\SpokenSummary;
use PHPUnit\Framework\TestCase;

final class SpokenSummaryTest extends TestCase
{
    public function testLeavesATextWithinTheCapUntouched(): void
    {
        $this->assertSame('Environ 44 N·m. Relevé sur la boîte auto.', SpokenSummary::fit('Environ 44 N·m. Relevé sur la boîte auto.', 200));
    }

    public function testKeepsWholeSentencesUpToTheCap(): void
    {
        $text = 'Première phrase. Deuxième phrase ! Troisième phrase beaucoup trop longue pour tenir ici ?';

        $this->assertSame('Première phrase. Deuxième phrase !', SpokenSummary::fit($text, 40));
    }

    public function testCutsAtTheLastWordBoundaryWhenTheFirstSentenceAloneIsTooLong(): void
    {
        $text = 'Une seule phrase interminable qui dépasse largement le plafond autorisé pour la voix.';

        $fitted = SpokenSummary::fit($text, 30);

        $this->assertSame('Une seule phrase…', $fitted);
        $this->assertLessThanOrEqual(30, mb_strlen($fitted));
    }

    public function testDoesNotSplitOnDecimalSeparators(): void
    {
        $this->assertSame('Compter 4.3 litres en tout.', SpokenSummary::fit('Compter 4.3 litres en tout. Puis contrôler le niveau après un court roulage.', 30));
    }
}
