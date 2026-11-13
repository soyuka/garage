<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Agent\Ui\RenderIntentTools;
use App\Agent\WebSearch\WebSearchGate;
use App\ApiResource\NarrowedParts;
use App\Catalog\CarType;
use App\Catalog\Narrowing\ApplicabilityScope;
use App\Catalog\Narrowing\ArticleNarrower;
use App\Catalog\Narrowing\NarrowingResult;
use App\Knowledge\BuildCode\BuildCodeDecoders;
use App\Knowledge\BuildCode\InvalidBuildCode;
use App\Knowledge\MakeName;
use App\Mcp\ToolError;
use App\Vehicle\VehicleIndex;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Webmozart\Assert\Assert;

/**
 * The finder's render intents, published as frames on the finder tab's turn session topic. Kept OUT
 * of the `ai.tool` tag (autoconfigure: false in services.yaml): `tools: true` would take every tagged
 * tool (`tools: true`), and these must reach the finder agent only, which lists this service by name.
 *
 * show_parts narrows server-side instead of taking the payload as an argument: the frame must carry
 * exactly what GET /narrowed returns (one client mapper), and a model transcribing a 220-article
 * analysis would be slow, costly and lossy. The narrowing reads the article cache narrow_parts fills.
 *
 * A refusal is a {@see ToolError}, the same shape the MCP tools fail with, so RepeatedToolFailureGuard
 * counts a model retrying it.
 *
 * @phpstan-import-type Divergence from NarrowingResult
 */
#[AsTool('show_parts', 'Show one catalogue part category on the finder sheet, for the vehicle of this turn: the server narrows the category (TecDoc, physically distinct parts, OE numbers, brand variants) and draws it. One call per category, at most 4 shown per turn. A category node can hold several TecDoc product groups (precise generic-article names: « Direction > Rotules » holds « Rotule de barre de connexion », the outer tie rod end, AND « Rotule de direction intérieure, barre de connexion », the inner axial joint): then status choose_group comes back with productGroups [{id, name, articleCount}] and nothing is drawn; pick the group whose name matches what the user asked (function and position) and call again with productGroupId. The title shown is the product group name, never your text. rationale is ONE short impersonal French sentence stating the fact that makes this part fit (cote, OE match, position, criterion, symptom cause), no reference number, never « Je… ». Returns a summary whose productGroup (and each cluster\'s productGroup) is what was drawn: check it against the request before end_turn. If clusters > 1 the finder itself asks the user the dimension question from differsOn, so do NOT ask it again, and each cluster carries its applicability (catalogue breakpoints per ARTICLE, or per variant from its own link to this car under the TecDoc catalogue: see applicabilityCaveat); status empty means the category (or that product group) holds no article for this vehicle and nothing was drawn: try the next most plausible sibling category once, then fall back to web_search + show_web_part. clusterId selects one of several clusters once something the user gave settles it (a decoded code de fabrication against the clusters\' breakpoints): pass the SAME categoryId and productGroupId as the result on screen plus the cluster id from its summary (c1, c2…); the sheet opens that variant marked « retenue d\'après » your rationale, which is then a short noun phrase naming the basis, e.g. « code de fabrication (OPR 09824 ≤ 10310) ». setAside, with clusterId only: a short noun phrase for why the other clusters do not fit this car, printed after « Ne correspond pas : », e.g. « OPR ≥ 10311 ». An id that is not one of that result\'s clusters is refused and nothing is drawn. Redrawing a category already shown this turn does not count against the 4.', 'showParts')]
#[AsTool('set_build_code', 'Decode the code de fabrication the user gave for THIS turn\'s car (typed, or read off the vignette photo) and pin it on the finder page\'s vehicle, so later turns on this car carry it. Use it instead of decode_build_code for the car\'s own code; decode_build_code stays for decoding a catalogue threshold or a date. Returns the same fields as decode_build_code (status decoded, organisationNumber, date, codeKind…).', 'setBuildCode')]
#[AsTool('show_web_part', 'Show a part reference found on the WEB (never the TecDoc catalogue) when the catalogue category is missing or empty. reference and brand exactly as the cited source writes them, sourceUrl/sourceTitle from the web_search citation you rely on. Never invent a reference: no citation, no call. rationale is ONE short impersonal French sentence stating what the source says that makes it fit this vehicle, never « Je… ».', 'showWebPart')]
#[AsTool('set_vehicle', 'Hand the vehicle you identified to the finder page, when the turn message says no vehicle is identified yet. carId/carType come from lookup_plate (plate) or list_cars (make/model browse); make, model and engine as the catalogue names them, plate only if the user gave one. Call it ONCE, before show_parts: show_parts narrows against this vehicle. Returns the vehicle\'s catalogue categories.', 'setVehicle')]
#[AsTool('ask_question', 'Ask the user ONE short French question, answerable in one tap, when the request is ambiguous before you can pick a category (which axle, which side, which of two parts). 2 to 6 concrete options, each {label shown on the button: a value such as « Avant », not a sentence; value sent back}. Never drop a matching candidate: offer all of them when there are 6 or fewer, otherwise ask a question that splits them first. The finder adds its own « Donner la plaque » button, or « Photo de la vignette » when the question names the code de fabrication: never offer either yourself, and every option must answer the question as asked. context is a short note to yourself restating what was asked and why, including the remaining candidate carIds; it comes back with the answer on the next turn. This ENDS the turn: call nothing after it.', 'askQuestion')]
final class FinderRenderTools
{
    public const MAX_CATEGORIES = 4;

    public const int MAX_OPTIONS = 6;

    private const int MAX_SET_ASIDE_LENGTH = 120;

    // Everything the agent writes reaches the screen through these frames: bounded, so a turn cannot
    // print an essay, whatever the request asked for.
    private const int MAX_RATIONALE_LENGTH = 200;

    private const int MAX_QUESTION_LENGTH = 160;

    private const int MAX_OPTION_LABEL_LENGTH = 60;

    private const int MAX_OPTION_VALUE_LENGTH = 120;

    private const int MAX_CONTEXT_LENGTH = 400;

    private const int MAX_LABEL_LENGTH = 80;

    private const int MAX_BRAND_LENGTH = 40;

    private const int MAX_REFERENCE_LENGTH = 40;

    private const int MAX_SOURCE_TITLE_LENGTH = 120;

    private ?FinderVehicle $vehicle = null;

    private ?string $organisationNumber = null;

    /** @var array<string, true> categoryId:productGroupId drawn this turn: a redraw (selection) is not a new category */
    private array $shown = [];

    public function __construct(
        private readonly RenderIntentTools $frames,
        private readonly ArticleNarrower $narrower,
        private readonly VehicleIndex $index,
        private readonly FinderCategoryList $categories,
        private readonly BuildCodeDecoders $decoders,
        private readonly FinderSelections $selections,
        private readonly WebSearchGate $webSearch,
        private readonly FinderVehicleResolver $vehicles,
    ) {
    }

    /** Bound once per finder turn by the handler — the worker is long-lived, so this also resets the per-turn count. */
    public function bindVehicle(?FinderVehicle $vehicle): void
    {
        $this->vehicle = $vehicle;
        $this->organisationNumber = $vehicle?->organisationNumber;
        $this->shown = [];
        $this->webSearch->bindVehicle($vehicle);
    }

    /**
     * @return array<string, mixed>|ToolError
     */
    public function showParts(int $categoryId, string $rationale, ?int $productGroupId = null, ?string $clusterId = null, ?string $setAside = null): array|ToolError
    {
        if (null === $this->vehicle) {
            return new ToolError('refused', 'No vehicle yet: identify it (lookup_plate or list_makes/list_models/list_cars), call set_vehicle, then show_parts.');
        }
        Assert::greaterThan($categoryId, 0);
        $clusterId = null === $clusterId || '' === trim($clusterId) ? null : trim($clusterId);
        $setAside = null === $setAside || '' === trim($setAside) ? null : self::cap($setAside, self::MAX_SET_ASIDE_LENGTH);
        $rationale = self::cap($rationale, self::MAX_RATIONALE_LENGTH);
        $key = $categoryId.':'.($productGroupId ?? '');
        if (!isset($this->shown[$key]) && \count($this->shown) >= self::MAX_CATEGORIES) {
            return new ToolError('refused', \sprintf('At most %d categories per turn: end the turn now.', self::MAX_CATEGORIES));
        }

        $result = $this->narrower->narrow($this->vehicle->carId, $this->vehicle->carType, $categoryId, $this->vehicle->make, $productGroupId);
        $narrowed = $result->toArray();
        if ($narrowed['groupRequired']) {
            return [
                'status' => 'choose_group',
                'reason' => null !== $clusterId
                    ? 'A clusterId only exists within one product group: nothing was drawn. Call show_parts again with the productGroupId of the result on screen (in its summary) and this clusterId.'
                    : 'This category holds several TecDoc product groups, which are different parts: nothing was drawn. Pick the group whose name matches the part the user asked for (function and position) and call show_parts again with its productGroupId. If none matches, look elsewhere.',
                'productGroups' => $narrowed['productGroups'],
            ];
        }
        if ([] === $narrowed['clusters']) {
            $this->webSearch->recordCatalogueMiss();
        }
        if (0 === $narrowed['totalArticles'] && [] === $narrowed['clusters']) {
            return ['status' => 'empty', 'reason' => 'The catalogue lists no article in this category (or this product group) for this vehicle: nothing was drawn. Try the next most plausible sibling category once, then web_search + show_web_part.']
                + (\count($narrowed['productGroups']) > 1 ? ['productGroups' => $narrowed['productGroups']] : []);
        }
        $clusterIds = array_column($narrowed['clusters'], 'id');
        if (null !== $clusterId && !\in_array($clusterId, $clusterIds, true)) {
            return new ToolError(
                'refused',
                \sprintf('clusterId %s is not a cluster of this result: nothing was drawn. Pick one of clusterIds, from the summary of this same categoryId and productGroupId.', $clusterId),
                ['clusterIds' => $clusterIds],
            );
        }
        $this->shown[$key] = true;
        $groupName = $narrowed['productGroup']['name'] ?? '';

        $selection = null === $clusterId ? null : $this->selections->record(
            $this->vehicle,
            $categoryId,
            $narrowed['productGroup']['id'] ?? $productGroupId,
            $clusterId,
            $rationale,
            $setAside,
            $this->organisationNumber,
        );

        $this->frames->publishFrame([
            'type' => 'showParts',
            'categoryId' => $categoryId,
            'categoryLabel' => $groupName,
            'rationale' => $rationale,
            'narrowed' => get_object_vars(NarrowedParts::from($this->vehicle->carId, $categoryId, $result)),
        ] + (null === $selection ? [] : ['selection' => ['id' => $selection->id, 'clusterId' => $clusterId, 'basis' => $selection->basis] + (null === $setAside ? [] : ['setAside' => $setAside])]));

        $several = \count($narrowed['clusters']) > 1;
        $scope = ApplicabilityScope::from($narrowed['applicabilityScope']);
        $summary = [
            'status' => 'shown',
            'categoryLabel' => $groupName,
            'productGroup' => $narrowed['productGroup'],
            'totalArticles' => $narrowed['totalArticles'],
            'narrowed' => $narrowed['narrowed'],
            'degradedReason' => $narrowed['degradedReason'],
            'clusters' => array_map(static fn (array $c): array => [
                'id' => $c['id'],
                'productGroup' => $c['productGroup']['name'] ?? $groupName,
                'representative' => $c['representative']['brand'].' '.$c['representative']['number'],
                'oe' => array_map(static fn (array $r): string => $r['brand'].' '.$r['number'], \array_slice($c['oeNumbers'], 0, 2)),
            ] + ($several ? ['applicability' => ClusterApplicability::line($c, $scope)] : []), $narrowed['clusters']),
            'differsOn' => array_map(self::differsLine(...), $narrowed['differsOn']),
        ] + (null === $selection ? [] : ['selected' => $clusterId, 'selectionId' => $selection->id]);
        if ($several) {
            $summary['applicabilityCaveat'] = ClusterApplicability::caveat($scope);
        }

        return $summary;
    }

    /**
     * @param Divergence $divergence
     */
    public static function differsLine(array $divergence): string
    {
        $values = array_map(static function (array $v): string {
            if (!isset($v['min'], $v['max'])) {
                return $v['clusterId'].' '.$v['value'];
            }
            $range = $v['min'] === $v['max'] ? (string) $v['min'] : $v['min'].'–'.$v['max'];

            $unit = $v['unit'] ?? null;

            return $v['clusterId'].' '.$range.(null === $unit ? '' : ' '.$unit);
        }, $divergence['values']);

        return $divergence['label'].' : '.implode(', ', $values);
    }

    public function showWebPart(string $categoryLabel, string $reference, string $brand, string $sourceUrl, string $sourceTitle, string $rationale): string|ToolError
    {
        Assert::stringNotEmpty(trim($reference));
        Assert::regex($sourceUrl, '#^https?://#', 'sourceUrl must be the http(s) URL of the cited source.');
        if (!$this->webSearch->cites($sourceUrl)) {
            return new ToolError('refused', 'sourceUrl is not a source cited by a web_search of this turn: nothing was drawn.');
        }
        if (!$this->webSearch->mentions($reference)) {
            return new ToolError('refused', 'this reference does not appear in what this turn\'s web_search returned: nothing was drawn. Copy it exactly as the cited source writes it.');
        }

        $this->frames->publishFrame([
            'type' => 'showWebPart',
            'categoryLabel' => self::cap($categoryLabel, self::MAX_LABEL_LENGTH),
            'reference' => self::cap($reference, self::MAX_REFERENCE_LENGTH),
            'brand' => self::cap($brand, self::MAX_BRAND_LENGTH),
            'sourceUrl' => $sourceUrl,
            'sourceTitle' => self::cap($sourceTitle, self::MAX_SOURCE_TITLE_LENGTH),
            'rationale' => self::cap($rationale, self::MAX_RATIONALE_LENGTH),
        ]);

        return 'ok';
    }

    public function setVehicle(int $carId, string $carType, string $make, string $model, string $engine = '', ?string $plate = null): string|ToolError
    {
        $type = CarType::tryFrom($carType);
        if ($carId <= 0 || null === $type) {
            return new ToolError('refused', 'carId must be a positive TecDoc kType and carType PC, CV or MC.');
        }
        try {
            $plate = FinderVehicleResolver::plate($plate);
        } catch (InvalidTurnInput) {
            $plate = null;
        }
        try {
            // The page shows the catalogue's names for this kType, never the agent's spelling of them.
            $vehicle = $this->vehicles->forCar($carId, $type, $plate);
        } catch (InvalidTurnInput $e) {
            return new ToolError('refused', $e->getMessage().': take the carId from lookup_plate or list_cars.');
        }
        $this->vehicle = $vehicle;
        $this->organisationNumber = null;
        $this->webSearch->bindVehicle($vehicle);
        $this->index->record($vehicle->carId, $vehicle->carType, $vehicle->make, $vehicle->model, $vehicle->engine, 'finder');

        $this->frames->publishFrame([
            'type' => 'setVehicle',
            'carId' => $vehicle->carId,
            'carType' => $vehicle->carType->value,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'engine' => $vehicle->engine,
            'plate' => $plate,
        ]);

        return "ok: the finder now shows this vehicle. Its catalogue categories (id + name, under [section]); pick by meaning and pass the id to show_parts:\n"
            .$this->categories->forVehicle($vehicle->carId, $vehicle->carType);
    }

    /**
     * @return array<string, mixed>|ToolError
     */
    public function setBuildCode(string $code): array|ToolError
    {
        if (null === $this->vehicle) {
            return new ToolError('refused', 'No vehicle yet: identify it (lookup_plate or list_makes/list_models/list_cars), call set_vehicle, then set_build_code.');
        }
        $make = MakeName::normalize($this->vehicle->make ?? '');
        if ('' === $make) {
            return new ToolError('refused', 'The make of this vehicle is unknown: nothing was pinned. Use decode_build_code with the make if you know it.');
        }
        $decoder = $this->decoders->forMake($make);
        if (null === $decoder) {
            return ['status' => 'unsupported_make', 'make' => $make, 'hint' => 'No build-code decoder for this make yet (only PSA: Peugeot, Citroën, DS). Do not guess a date.'];
        }

        try {
            $decoded = $decoder->decode($code);
        } catch (InvalidBuildCode $e) {
            return new ToolError('invalid_input', $e->getMessage());
        }

        $this->organisationNumber = $decoded->organisationNumber;
        $this->frames->publishFrame([
            'type' => 'setBuildCode',
            'organisationNumber' => $decoded->organisationNumber,
            'date' => $decoded->date->format('Y-m-d'),
            'codeKind' => $decoded->kind,
        ]);

        return ['status' => 'decoded', 'make' => $make] + $decoded->toArray();
    }

    /**
     * @param list<QuestionOption> $options
     */
    public function askQuestion(string $question, #[Schema(minItems: 2, maxItems: self::MAX_OPTIONS)] array $options, string $context): string
    {
        Assert::stringNotEmpty(trim($question));
        Assert::allIsInstanceOf($options, QuestionOption::class);
        Assert::countBetween($options, 2, self::MAX_OPTIONS);

        $this->frames->publishFrame([
            'type' => 'askQuestion',
            'question' => self::cap($question, self::MAX_QUESTION_LENGTH),
            'options' => array_map(static fn (QuestionOption $o): array => ['label' => self::cap($o->label, self::MAX_OPTION_LABEL_LENGTH), 'value' => self::cap($o->value, self::MAX_OPTION_VALUE_LENGTH)], $options),
            'context' => self::cap($context, self::MAX_CONTEXT_LENGTH),
        ]);

        return 'ok';
    }

    private static function cap(string $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)), 0, $max);
    }
}
