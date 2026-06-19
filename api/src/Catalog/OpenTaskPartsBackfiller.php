<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Deterministically fills an open checklist task's empty shopping list from a get_service_parts
 * result. Without this the agent has to map every returned catalog category onto the right task by
 * hand — and it does not: the live Mazda6 had air/cabin filters priced but its oil_filter task left
 * empty, which forced the broken "add a web-found reference" CTA loop. This closes that gap: when a
 * service is priced, any matching empty task gets its refs straight from TecDoc.
 *
 * Additive and idempotent: it only ever touches an OPEN task whose parts list is EMPTY, so it never
 * overwrites a ref the agent or the user already attached. The task-type → category match is the
 * fragile part (a substring test on the catalog category NAME); the map is kept explicit on purpose.
 */
final readonly class OpenTaskPartsBackfiller
{
    /**
     * Task type → case-insensitive needles matched against a catalog category name. The catalog
     * search terms ({@see ServiceKind::categorySearches()}) decide which categories appear; these
     * decide which open task each category feeds. Keys are exact task types; brake_* matches by prefix.
     *
     * @var array<string, list<string>>
     */
    private const NEEDLES = [
        'oil_filter' => ['huile', 'oil'],
        'air_filter' => ['air'],
        'cabin_filter' => ['habitacle', 'pollen', 'cabin', 'intérieur'],
        'fuel_filter' => ['carburant', 'fuel', 'gasoil', 'essence'],
        'spark_plug' => ['bougie', 'spark'],
    ];

    /**
     * How many brand options to carry per task — matches the multi-brand shortlist the agent already
     * attaches (e.g. air_filter shows MANN + MAHLE + BOSCH), so the user picks from the same set.
     */
    private const BRANDS_PER_TASK = 3;

    public function __construct(
        private PlannedMaintenanceRepository $tasks,
        private DocumentManager $entityManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $categories the `categories` payload of a get_service_parts result
     *
     * @return int how many tasks were filled
     */
    public function backfill(Vehicle $vehicle, array $categories): int
    {
        $filled = 0;
        foreach ($this->tasks->checklistForVehicle($vehicle) as $task) {
            if (!$task->isOpen() || [] !== $task->getParts()) {
                continue;
            }

            $category = $this->matchCategory($task, $categories);
            if (null === $category) {
                continue;
            }

            $parts = $this->partsFromCategory($category);
            if ([] === $parts) {
                continue;
            }

            $task->mergeParts($parts);
            ++$filled;
        }

        if ($filled > 0) {
            $this->entityManager->flush();
        }

        return $filled;
    }

    /**
     * @param list<array<string, mixed>> $categories
     *
     * @return array<string, mixed>|null
     */
    private function matchCategory(PlannedMaintenance $task, array $categories): ?array
    {
        $needles = $this->needlesFor($task->getType());
        if ([] === $needles) {
            return null;
        }

        foreach ($categories as $category) {
            $name = (string) ($category['name'] ?? '');
            foreach ($needles as $needle) {
                if ('' !== $name && false !== mb_stripos($name, $needle)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function needlesFor(string $type): array
    {
        if (isset(self::NEEDLES[$type])) {
            return self::NEEDLES[$type];
        }

        return str_starts_with($type, 'brake') ? ['frein', 'brake'] : [];
    }

    /**
     * Top brands of a category, one fitting ref each, labelled by the category name — the same shape
     * plan_maintenance stores (parts:[{ref, brand, label}]).
     *
     * @param array<string, mixed> $category
     *
     * @return list<array{ref: string, brand: string, label: string}>
     */
    private function partsFromCategory(array $category): array
    {
        $name = (string) ($category['name'] ?? '');
        $parts = [];
        foreach (\array_slice($category['brands'] ?? [], 0, self::BRANDS_PER_TASK) as $group) {
            $article = $group['articles'][0] ?? null;
            if (null === $article || !isset($article['number'])) {
                continue;
            }
            $parts[] = ['ref' => (string) $article['number'], 'brand' => (string) ($group['brand'] ?? ''), 'label' => $name];
        }

        return $parts;
    }
}
