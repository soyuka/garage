<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Catalog\OpenTaskPartsBackfiller;
use App\Catalog\PartsCatalog;
use App\Catalog\ServiceKind;
use App\Dto\ServicePartsInput;
use App\Garage\ToolResult;
use App\Mcp\ArticleGroup;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_service_parts` tool: the intent-level shortcut over the catalog primitives.
 * For a service (oil change, filters, brakes, spark plugs) it resolves the relevant part
 * categories and their articles in one call — so the host does
 * not have to walk list_part_categories → list_articles by hand.
 *
 * @implements ProcessorInterface<ServicePartsInput, CallToolResult>
 */
final class ServicePartsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PartsCatalog $catalog,
        private readonly VehicleRepository $vehicles,
        private readonly OpenTaskPartsBackfiller $backfiller,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $service = ServiceKind::tryFrom($data->service);
        if (null === $service) {
            return ToolResult::of([
                'status' => 'error',
                'message' => sprintf('Unknown service "%s".', $data->service),
                'validServices' => array_map(static fn (ServiceKind $s): string => $s->value, ServiceKind::cases()),
            ]);
        }

        $carType = CarTypeResolver::fromString($data->carType);

        // resolve matching leaf categories, deduped by id across the service's search terms
        $cats = [];
        foreach ($service->categorySearches() as $term) {
            foreach ($this->catalog->partCategories($data->carId, $carType, true, $term) as $cat) {
                $cats[$cat->id] = $cat;
            }
        }

        $categories = [];
        foreach ($cats as $cat) {
            $categories[] = [
                'name' => $cat->name,
                'path' => $cat->path,
                ...ArticleGroup::of($cat->id, $this->catalog->articles($data->carId, $cat->id, $carType)),
            ];
        }

        // Opportunistic backfill: a vehicle already in the garage under this kType gets its empty
        // open tasks filled with these refs, so the priced parts land on the checklist without the
        // agent (or a CTA loop) having to re-attach them. Catalog-only calls (carId not in the
        // garage) just skip — the tool stays a pure read for them.
        foreach ($this->vehicles->findByKType($data->carId) as $vehicle) {
            if ($this->backfiller->backfill($vehicle, $categories) > 0) {
                $this->publisher->publishVehicle($vehicle);
            }
        }

        return ToolResult::of([
            'status' => 'ok',
            'service' => $service->value,
            'carId' => $data->carId,
            'categories' => $categories,
        ]);
    }
}
