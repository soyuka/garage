<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\Applicability;
use App\Catalog\VehicleLinkCriteria;
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

    public function testALinkKeepsEveryQualifierPerSetAndTakesThePairingApartById(): void
    {
        $alternatives = Applicability::ofLink(new VehicleLinkCriteria([], [
            [new ArticleCriterion("Année jusqu'à", '200602', 21), new ArticleCriterion('Code moteur', 'F4R 797/897', 33), new ArticleCriterion('uniquement en connexion avec', '3000 951 220', 427)],
            [new ArticleCriterion("Côté d'assemblage", 'VL', 16), new ArticleCriterion("Côté d'assemblage", 'VR', 16)],
        ]));

        $this->assertSame([
            [
                'conditions' => [['name' => "Année jusqu'à", 'values' => ['200602']], ['name' => 'Code moteur', 'values' => ['F4R 797/897']]],
                'pairedWith' => ['3000 951 220'],
            ],
            ['conditions' => [['name' => "Côté d'assemblage", 'values' => ['VL', 'VR']]], 'pairedWith' => []],
        ], $alternatives);
        $this->assertSame("Année jusqu'à : 200602, Code moteur : F4R 797/897, only with 3000 951 220 OR Côté d'assemblage : VL, VR", Applicability::describe($alternatives));
        $this->assertSame('no condition', Applicability::describe(Applicability::ofLink(new VehicleLinkCriteria())));
    }

    public function testAClusterSharesOnlyTheConditionsEveryVariantHoldsInEveryAlternative(): void
    {
        $dated = ['conditions' => [['name' => "Année jusqu'à", 'values' => ['200602']], ['name' => 'Code moteur', 'values' => ['F4R']]], 'pairedWith' => ['X']];
        $otherEngine = ['conditions' => [['name' => "Année jusqu'à", 'values' => ['200602']], ['name' => 'Code moteur', 'values' => ['M9R']]], 'pairedWith' => []];

        $this->assertSame([['name' => "Année jusqu'à", 'values' => ['200602']]], Applicability::shared([[$dated], [$dated, $otherEngine]]));
        $this->assertSame([], Applicability::shared([[$dated], []]));
        $this->assertSame([], Applicability::shared([]));
    }
}
