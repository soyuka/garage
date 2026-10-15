<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\QueryParameter;
use App\Catalog\Narrowing\Applicability;
use App\Catalog\Narrowing\NarrowingResult;
use App\Dto\NarrowPartsInput;
use App\State\NarrowedPartsProvider;
use App\State\NarrowPartsProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fitment-correct articles of one part category, collapsed into the physically distinct parts
 * they really are (step 5, narrowed). See {@see \App\Catalog\Narrowing\ArticleNarrower}.
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
                    description: 'The car\'s make (e.g. MAZDA), to recognise its own OE numbers',
                    schema: ['type' => 'string'],
                ),
            ],
        ),
    ],
    mcp: [
        'narrow_parts' => new McpTool(
            title: 'Narrow Parts',
            description: 'Collapse the many fitment-correct articles of one part category (a popular oil filter has 220 across 126 brands) into the few PHYSICALLY DISTINCT parts they are, each with its OE numbers and every brand variant, ranked by tier. ALWAYS pass `make` (the car\'s make from lookup_plate): sizes the carmaker itself lists as interchangeable (shared carmaker OE number) are merged into ONE part, whose dimensions then come as a min-max range. PREFER THIS over list_articles whenever the user wants THE right reference rather than a brand shortlist. Use carId/carType from lookup_plate and categoryId from list_part_categories (one category per call). When `clusters` has more than one entry the car fits several different parts: ask the user ONE question built from `differsOn` (e.g. "85 or 93 mm tall?"), the cluster values say which part each answer selects; never list every reference. `unclassifiedCount` articles fit the car but could not be assigned to a part. Each cluster\'s `applicability` lists the catalogue\'s application breakpoints verbatim (years, VIN/chassis, numéro d\'organisation, serial), with `applicabilityScope: "article"`: they come per ARTICLE, the union over every vehicle the article fits, NOT per car. Use them only to choose between clusters that already differ physically or by OE number, never to split or discard a single cluster, and never as proof that a part is right for a build date or chassis number; the part itself (dimensions, thread, OE number) is what to confirm, ideally against the old part or the carte grise. `narrowed: false` means upstream quota or an outage cut the analysis short: say so, and use what came back (`degradedReason`, `fallbackBrands`). Costs up to ~20 catalogue calls the first time, none once cached.',
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
     * @param list<array<string, mixed>>      $clusters
     * @param list<array<string, mixed>>      $differsOn
     * @param list<array<string, mixed>>|null $fallbackBrands
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
        public string $applicabilityScope = Applicability::SCOPE,
    ) {
    }

    public static function from(int $carId, int $categoryId, NarrowingResult $result): self
    {
        $data = $result->toArray();

        return new self(
            carId: $carId,
            categoryId: $categoryId,
            clusters: $data['clusters'],
            totalArticles: $data['totalArticles'],
            totalBrands: $data['totalBrands'],
            unclassifiedCount: $data['unclassifiedCount'],
            differsOn: $data['differsOn'],
            narrowed: $data['narrowed'],
            degradedReason: $data['degradedReason'],
            upstreamCalls: $data['upstreamCalls'],
            fallbackBrands: $data['fallbackBrands'],
            applicabilityScope: $data['applicabilityScope'],
        );
    }
}
