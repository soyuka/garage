<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\NarrowPartsInput;
use App\State\NarrowedPartsProvider;
use App\State\NarrowPartsProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fitment-correct articles of one part category, collapsed into the physically distinct parts
 * they really are (step 5, narrowed).
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/cars/{carId}/part-categories/{categoryId}/narrowed',
            uriVariables: ['carId', 'categoryId'],
            provider: NarrowedPartsProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type of the carId: PC (default) or CV',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
                'make' => new QueryParameter(
                    description: 'Deprecated, ignored when the server knows the car: the make is resolved from carId (vehicle index)',
                    schema: ['type' => 'string'],
                ),
                'productGroupId' => new QueryParameter(
                    description: 'TecDoc product group (PT_ID) to narrow inside, from productGroups, when the category holds several',
                    schema: ['type' => 'integer', 'minimum' => 1],
                    constraints: [new Assert\Positive()],
                ),
            ],
        ),
    ],
    mcp: [
        'narrow_parts' => new McpTool(
            title: 'Narrow Parts',
            description: 'Collapse the many fitment-correct articles of one part category (a popular oil filter has 220 across 126 brands) into the few PHYSICALLY DISTINCT parts they are, each with its OE numbers and every brand variant, ranked by tier. Sizes the carmaker itself lists as interchangeable (shared carmaker OE number) are merged into ONE part, whose dimensions then come as a min-max range; the server resolves the car\'s make from carId (pass `make` only as a fallback for a car it does not know). PREFER THIS over list_articles whenever the user wants THE right reference rather than a brand shortlist. Use carId/carType from lookup_plate and categoryId from list_part_categories (one category per call). When `clusters` has more than one entry the car fits several different parts: ask the user ONE question built from `differsOn` (e.g. "85 or 93 mm tall?"), the cluster values say which part each answer selects; never list every reference. `unclassifiedCount` articles fit the car but could not be assigned to a part. Where a part applies depends on `applicabilityScope`. "vehicle": each VARIANT carries `applicability`, read from that article\'s own link to THIS car, a list of alternatives (any one may hold, an empty list means the link states no condition), each with `conditions` [{name, values}] verbatim (year, VIN/chassis, engine code, gearbox…) that must ALL hold, and `pairedWith`, the references it must be fitted with ("uniquement en connexion avec": which clutch kit goes with which flywheel, say it whenever it is set); a condition of one variant says nothing about the other variants of its cluster, and the cluster-level `applicability` [{name, values}] keeps only the conditions EVERY variant of the cluster holds. "article": the variants carry none, and each cluster\'s `applicability` lists the catalogue\'s breakpoints verbatim (years, VIN/chassis, numéro d\'organisation, serial) as the union over every vehicle its articles fit, NOT per car. Either way use them only to choose between clusters that already differ physically or by OE number or to pair parts, never to split or discard a single cluster, and never as proof that a part is right for a build date or chassis number; the part itself (dimensions, thread, OE number) is what to confirm, ideally against the old part or the carte grise. `narrowed: false` means upstream quota or an outage cut the analysis short: say so, and use what came back (`degradedReason`, `fallbackBrands`). A category node can hold several TecDoc product groups (generic articles: "Direction > Rotules" holds outer tie rod ends AND inner axial joints): then `groupRequired: true` comes back with `productGroups` [{id, name, articleCount}] and nothing narrowed (no catalogue call spent on criteria). Pick the group whose name matches the part the user means and call again with `productGroupId`; `productGroup` names the group a result was narrowed in. Costs up to ~20 catalogue calls the first time, none once cached.',
            input: NarrowPartsInput::class,
            structuredContent: false,
            processor: NarrowPartsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class NarrowedParts
{
    /**
     * @param list<array<string, mixed>>                            $clusters
     * @param list<array<string, mixed>>                            $differsOn
     * @param list<array<string, mixed>>|null                       $fallbackBrands
     * @param array{id: int, name: string}|null                     $productGroup
     * @param list<array{id: int, name: string, articleCount: int}> $productGroups
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $carId,
        #[ApiProperty(identifier: true)]
        public int $categoryId,
        public array $clusters,
        public int $totalArticles,
        public int $totalBrands,
        public int $unclassifiedCount,
        public array $differsOn,
        public bool $narrowed,
        public ?string $degradedReason,
        public int $upstreamCalls,
        public ?array $fallbackBrands = null,
        public string $applicabilityScope = 'article',
        public ?array $productGroup = null,
        public array $productGroups = [],
        public bool $groupRequired = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        return new self(
            carId: (int) ($data['carId'] ?? 0),
            categoryId: (int) ($data['categoryId'] ?? 0),
            clusters: $data['clusters'] ?? [],
            totalArticles: (int) ($data['totalArticles'] ?? 0),
            totalBrands: (int) ($data['totalBrands'] ?? 0),
            unclassifiedCount: (int) ($data['unclassifiedCount'] ?? 0),
            differsOn: $data['differsOn'] ?? [],
            narrowed: (bool) ($data['narrowed'] ?? false),
            degradedReason: $data['degradedReason'] ?? null,
            upstreamCalls: (int) ($data['upstreamCalls'] ?? 0),
            fallbackBrands: $data['fallbackBrands'] ?? null,
            applicabilityScope: (string) ($data['applicabilityScope'] ?? 'article'),
            productGroup: $data['productGroup'] ?? null,
            productGroups: $data['productGroups'] ?? [],
            groupRequired: (bool) ($data['groupRequired'] ?? false),
        );
    }
}
