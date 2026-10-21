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

    public function testKeepsTheTecdoc2020ChassisAndSerialSpellings(): void
    {
        $applicability = Applicability::fromCriteria([
            new ArticleCriterion('Nº de châssis à partir de', '130831; 176451'),
            new ArticleCriterion('Nº de série', 'GG; GY'),
            new ArticleCriterion('Nº d\'organisation à partir de', '07665'),
            new ArticleCriterion('Code moteur', 'LF'),
        ]);

        $this->assertSame([
            'Nº de châssis à partir de' => ['130831', '176451'],
            'Nº de série' => ['GG', 'GY'],
            'Nº d\'organisation à partir de' => ['07665'],
        ], $applicability);
    }
}
