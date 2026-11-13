<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\Applicability;
use App\Catalog\VehicleLinkCriteria;
use App\I18n\Locale;
use App\Tests\Support\TecdocCriteria;
use PHPUnit\Framework\TestCase;

final class ApplicabilityTest extends TestCase
{
    public function testKeepsTheOrganisationNumberBreakpointsPsaSuppliersCallOpr(): void
    {
        $applicability = Applicability::fromCriteria([
            TecdocCriteria::of("Nº d'organisation jusqu'à", '10310'),
            TecdocCriteria::of("Nº d'organisation à partir de", '10311'),
            TecdocCriteria::of('Longueur [mm]', '340'),
        ]);

        $this->assertSame([
            "Nº d'organisation jusqu'à" => ['10310'],
            "Nº d'organisation à partir de" => ['10311'],
        ], $applicability);
    }

    public function testKeepsTheTecdoc2020ChassisAndSerialSpellings(): void
    {
        $applicability = Applicability::fromCriteria([
            TecdocCriteria::of('Nº de châssis à partir de', '130831; 176451'),
            TecdocCriteria::of('Nº de série', 'GG; GY'),
            TecdocCriteria::of("Nº d'organisation à partir de", '07665'),
            TecdocCriteria::of('Code moteur', 'LF'),
        ]);

        $this->assertSame([
            'Nº de châssis à partir de' => ['130831', '176451'],
            'Nº de série' => ['GG', 'GY'],
            "Nº d'organisation à partir de" => ['07665'],
        ], $applicability);
    }

    public function testTheBreakpointsAreKeptUnderTheirEnglishNamesAndNeverWithoutAnId(): void
    {
        $applicability = Applicability::fromCriteria([
            new ArticleCriterion('Chassis number from', '130831', 25),
            new ArticleCriterion('Serial Number', 'GG', 749),
            new ArticleCriterion('RPO number from', '07665', 45),
            new ArticleCriterion('Engine Code', 'LF', 33),
            new ArticleCriterion('Chassis number to', '176451'),
        ]);

        $this->assertSame(['Chassis number from' => ['130831'], 'Serial Number' => ['GG'], 'RPO number from' => ['07665']], $applicability);
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
        $this->assertSame("Année jusqu'à : 200602, Code moteur : F4R 797/897, uniquement avec 3000 951 220 OU Côté d'assemblage : VL, VR", Applicability::describe($alternatives, Locale::Fr));
        $this->assertSame("Année jusqu'à: 200602, Code moteur: F4R 797/897, only with 3000 951 220 OR Côté d'assemblage: VL, VR", Applicability::describe($alternatives, Locale::En));
        $this->assertSame('aucune condition', Applicability::describe(Applicability::ofLink(new VehicleLinkCriteria()), Locale::Fr));
        $this->assertSame('no condition', Applicability::describe(Applicability::ofLink(new VehicleLinkCriteria()), Locale::En));
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
