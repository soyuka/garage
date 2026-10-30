<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Catalog\Narrowing\Applicability;
use App\Catalog\Narrowing\ApplicabilityScope;

/**
 * The applicability of one narrowed cluster as the show_parts summary words it for the finder agent.
 */
final class ClusterApplicability
{
    private const int MAX_VALUES = 12;

    private const array CAVEATS = [
        'article' => 'applicability is per ARTICLE (the union over every vehicle the article fits), not per car: use it only to choose between clusters that already differ, never as proof, never to split or discard one.',
        'vehicle' => 'applicability is per variant, read from that article\'s own link to this car (variants not listed carry no condition): alternatives are joined by OR, "only with" names the part it must be fitted with. Use it to choose between clusters that already differ and to pair parts, never to split or discard a cluster.',
    ];

    /**
     * @param array<string, mixed> $cluster a NarrowedCluster as an array
     */
    public static function line(array $cluster, ApplicabilityScope $scope): string
    {
        $parts = [];
        if (ApplicabilityScope::Vehicle === $scope) {
            foreach ($cluster['variants'] ?? [] as $variant) {
                if ([] !== ($variant['applicability'] ?? [])) {
                    $parts[] = $variant['brand'].' '.$variant['number'].': '.Applicability::describe($variant['applicability']);
                }
            }
        } else {
            foreach ($cluster['applicability'] ?? [] as $entry) {
                $values = \array_slice($entry['values'], 0, self::MAX_VALUES);
                $more = \count($entry['values']) > self::MAX_VALUES ? ', …' : '';
                $parts[] = $entry['name'].' : '.implode(', ', $values).$more;
            }
        }

        return [] === $parts ? 'none listed' : implode(' | ', $parts);
    }

    public static function caveat(ApplicabilityScope $scope): string
    {
        return self::CAVEATS[$scope->value];
    }
}
