<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Finder;

use App\Agent\Finder\ClusterApplicability;
use App\Catalog\Narrowing\ApplicabilityScope;
use App\Catalog\Narrowing\NarrowedCluster;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ClusterArray from NarrowedCluster
 * @phpstan-import-type Variant from NarrowedCluster
 */
final class ClusterApplicabilityTest extends TestCase
{
    public function testUnderTheArticleScopeTheClusterUnionIsListedWithItsCaveat(): void
    {
        $cluster = self::cluster(applicability: [['name' => 'Année à partir de', 'values' => ['200406', '200907']]]);

        $this->assertSame('Année à partir de : 200406, 200907', ClusterApplicability::line($cluster, ApplicabilityScope::Article));
        $this->assertSame('none listed', ClusterApplicability::line(self::cluster(), ApplicabilityScope::Article));
        $this->assertStringContainsString('per ARTICLE', ClusterApplicability::caveat(ApplicabilityScope::Article));
    }

    public function testUnderTheVehicleScopeEachVariantSpeaksForItselfOnly(): void
    {
        $cluster = self::cluster(variants: [
            ['artId' => 1, 'tier' => 1, 'brand' => 'LuK', 'number' => '415 0463 10', 'applicability' => [['conditions' => [], 'pairedWith' => ['623 3150 ##']]]],
            ['artId' => 2, 'tier' => 1, 'brand' => 'SACHS', 'number' => '2294 501 196', 'applicability' => [['conditions' => [['name' => "Année jusqu'à", 'values' => ['200602']]], 'pairedWith' => ['3000 951 220']]]],
            ['artId' => 3, 'tier' => 1, 'brand' => 'FEBI', 'number' => '1', 'applicability' => []],
        ]);

        $this->assertSame(
            "LuK 415 0463 10: only with 623 3150 ## | SACHS 2294 501 196: Année jusqu'à : 200602, only with 3000 951 220",
            ClusterApplicability::line($cluster, ApplicabilityScope::Vehicle),
        );
        $this->assertSame('none listed', ClusterApplicability::line(self::cluster(variants: [['artId' => 3, 'tier' => 1, 'brand' => 'FEBI', 'number' => '1', 'applicability' => []]]), ApplicabilityScope::Vehicle));
        $this->assertStringContainsString('this car', ClusterApplicability::caveat(ApplicabilityScope::Vehicle));
    }

    /**
     * @param list<array{name: string, values: list<string>}> $applicability
     * @param list<Variant>                                   $variants
     *
     * @return ClusterArray
     */
    private static function cluster(array $applicability = [], array $variants = []): array
    {
        return (new NarrowedCluster('c1', [], [], ['artId' => 1, 'brand' => 'LuK', 'number' => '1'], $variants, 1, $applicability))->toArray();
    }
}
