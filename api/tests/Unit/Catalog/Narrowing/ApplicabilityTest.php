<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\Applicability;
use PHPUnit\Framework\TestCase;

final class ApplicabilityTest extends TestCase
{
    public function testKeepsTheOprBreakpointPsaSuppliersSpellWithoutOrganisation(): void
    {
        $applicability = Applicability::fromCriteria([
            new ArticleCriterion('pour numéro OPR jusqu’à', '10310'),
            new ArticleCriterion('pour numéro OPR à partir de', '10311'),
            new ArticleCriterion('Longueur [mm]', '340'),
        ]);

        $this->assertSame([
            'pour numéro OPR jusqu’à' => ['10310'],
            'pour numéro OPR à partir de' => ['10311'],
        ], $applicability);
    }
}
