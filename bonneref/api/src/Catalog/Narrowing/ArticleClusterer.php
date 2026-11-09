<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

/**
 * Groups representatives that could be one physical part. Clustering is on physical dimensions only
 * and never on crosses: partsapi crosses are a loose interchange list, so connected components over
 * them would merge differently sized parts.
 */
final class ArticleClusterer
{
    /**
     * @param list<Representative> $representatives
     *
     * @return list<Cluster>
     */
    public function cluster(array $representatives): array
    {
        $candidates = array_values(array_filter($representatives, static fn (Representative $r): bool => !$r->fingerprint->isEmpty()));

        // A total order, so the greedy pass below gives the same answer whatever order the caller used.
        usort($candidates, Representative::compare(...));

        /** @var list<Cluster> $clusters */
        $clusters = [];
        foreach ($candidates as $candidate) {
            foreach ($clusters as $cluster) {
                // Tested against the union of the cluster, so two members that each skip a different
                // key cannot be bridged into one cluster by a third.
                if ($cluster->fingerprint->agreesWith($candidate->fingerprint)) {
                    $cluster->members[] = $candidate;
                    $cluster->fingerprint = $cluster->fingerprint->completedBy($candidate->fingerprint);
                    continue 2;
                }
            }

            $clusters[] = new Cluster('c'.(\count($clusters) + 1), [$candidate], $candidate->fingerprint);
        }

        return $clusters;
    }

    /**
     * The splitting criteria the clusters disagree on, then the millimetre info measures (never splitting,
     * suppliers disagree on them) that still tell clusters apart once something else split them: the 307
     * axial joints split on carmaker OE alone and differ by 20 mm in length.
     *
     * @param list<Cluster> $clusters
     *
     * @return list<array{key: string, label: string, values: list<array{clusterId: string, label: string, value: string, min?: float, max?: float, unit?: ?string, info?: true}>}>
     */
    public function differsOn(array $clusters): array
    {
        /** @var array<string, list<array{cluster: Cluster, entry: FingerprintEntry}>> $byKey */
        $byKey = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->merged()->entries as $key => $entry) {
                if ($entry->info && ('mm' !== $entry->unit || str_contains($key, 'emballage'))) {
                    continue;
                }
                $byKey[$key][] = ['cluster' => $cluster, 'entry' => $entry];
            }
        }

        $differs = [];
        foreach ($byKey as $key => $holders) {
            if (\count($holders) < 2) {
                continue;
            }

            $disagree = false;
            foreach ($holders as $i => $a) {
                foreach (\array_slice($holders, $i + 1) as $b) {
                    $disagree = $disagree || !$a['entry']->agreesWith($b['entry']);
                }
            }
            if (!$disagree) {
                continue;
            }

            $differs[] = [
                'key' => $key,
                'label' => $holders[0]['entry']->label,
                'values' => array_map(
                    static fn (array $h): array => ['clusterId' => $h['cluster']->id] + $h['entry']->toArray(),
                    $holders,
                ),
            ];
        }

        usort($differs, static fn (array $a, array $b): int => ($a['values'][0]['info'] ?? false) <=> ($b['values'][0]['info'] ?? false));

        return $differs;
    }
}
