<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\ApiResource\NarrowedParts;
use App\Agent\Ui\RenderIntentTools;
use App\Catalog\Narrowing\ArticleNarrower;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webmozart\Assert\Assert;

/**
 * The finder's render intents, published as frames on the finder tab's turn session topic. Kept OUT
 * of the `ai.tool` tag (autoconfigure: false in services.yaml): the garage agent takes every tagged
 * tool (`tools: true`), and these must reach the finder agent only, which lists this service by name.
 *
 * show_parts narrows server-side instead of taking the payload as an argument: the frame must carry
 * exactly what GET /narrowed returns (one client mapper), and a model transcribing a 220-article
 * analysis would be slow, costly and lossy. The narrowing reads the article cache narrow_parts fills.
 */
#[AsTool('show_parts', 'Show one catalogue part category on the finder sheet, for the vehicle of this turn: the server narrows the category (TecDoc, physically distinct parts, OE numbers, brand variants) and draws it. One call per category, at most 4 per turn. categoryLabel is the short French name shown as the title, sentence case (« Filtre à huile »). rationale is ONE short impersonal French sentence stating the fact that makes this part fit (cote, OE match, position, criterion, symptom cause), no reference number, never « Je… ». Returns a summary: if clusters > 1 the finder itself asks the user the dimension question from differsOn, so do NOT ask it again, and each cluster carries its applicability (catalogue breakpoints per ARTICLE, not per car: see applicabilityCaveat); if totalArticles is 0 or narrowed is false with no clusters, the catalogue has nothing usable: fall back to web_search + show_web_part.', 'showParts')]
#[AsTool('show_web_part', 'Show a part reference found on the WEB (never the TecDoc catalogue) when the catalogue category is missing or empty. reference and brand exactly as the cited source writes them, sourceUrl/sourceTitle from the web_search citation you rely on. Never invent a reference: no citation, no call. rationale is ONE short impersonal French sentence stating what the source says that makes it fit this vehicle, never « Je… ».', 'showWebPart')]
#[AsTool('set_vehicle', 'Hand the vehicle you identified to the finder page, when the turn message says no vehicle is identified yet. carId/carType come from lookup_plate (plate) or list_cars (make/model browse); make, model and engine as the catalogue names them, plate only if the user gave one. Call it ONCE, before show_parts: show_parts narrows against this vehicle.', 'setVehicle')]
#[AsTool('ask_question', 'Ask the user ONE short French question, answerable in one tap, when the request is ambiguous before you can pick a category (which axle, which side, which of two parts). 2 to 4 concrete options, each {label shown on the button: a value such as « Avant », not a sentence; value sent back}. context is a short note to yourself restating what was asked and why; it comes back with the answer on the next turn. This ENDS the turn: call nothing after it.', 'askQuestion')]
final class FinderRenderTools
{
    public const MAX_CATEGORIES = 4;

    private const int MAX_APPLICABILITY_VALUES = 12;

    private const string APPLICABILITY_CAVEAT = 'applicability is per ARTICLE (the union over every vehicle the article fits), not per car: use it only to choose between clusters that already differ, never as proof, never to split or discard one.';

    private ?FinderVehicle $vehicle = null;

    private int $shown = 0;

    public function __construct(
        private readonly RenderIntentTools $frames,
        private readonly ArticleNarrower $narrower,
    ) {
    }

    /** Bound once per finder turn by the handler — the worker is long-lived, so this also resets the per-turn count. */
    public function bindVehicle(?FinderVehicle $vehicle): void
    {
        $this->vehicle = $vehicle;
        $this->shown = 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function showParts(int $categoryId, string $categoryLabel, string $rationale): array
    {
        if (null === $this->vehicle) {
            return ['status' => 'refused', 'reason' => 'No vehicle yet: identify it (lookup_plate or list_makes/list_models/list_cars), call set_vehicle, then show_parts.'];
        }
        Assert::greaterThan($categoryId, 0);
        Assert::stringNotEmpty(trim($categoryLabel));
        if ($this->shown >= self::MAX_CATEGORIES) {
            return ['status' => 'refused', 'reason' => \sprintf('At most %d categories per turn: end the turn now.', self::MAX_CATEGORIES)];
        }
        ++$this->shown;

        $narrowed = get_object_vars(NarrowedParts::from(
            $this->vehicle->carId,
            $categoryId,
            $this->narrower->narrow($this->vehicle->carId, $this->vehicle->carType, $categoryId, $this->vehicle->make),
        ));

        $this->frames->publishFrame([
            'type' => 'showParts',
            'categoryId' => $categoryId,
            'categoryLabel' => trim($categoryLabel),
            'rationale' => trim($rationale),
            'narrowed' => $narrowed,
        ]);

        $several = \count($narrowed['clusters']) > 1;
        $summary = [
            'status' => 'shown',
            'totalArticles' => $narrowed['totalArticles'],
            'narrowed' => $narrowed['narrowed'],
            'degradedReason' => $narrowed['degradedReason'],
            'clusters' => array_map(static fn (array $c): array => [
                'id' => $c['id'],
                'representative' => $c['representative']['brand'].' '.$c['representative']['number'],
                'oe' => array_map(static fn (array $r): string => $r['brand'].' '.$r['number'], \array_slice($c['oeNumbers'], 0, 2)),
            ] + ($several ? ['applicability' => self::applicabilityLine($c['applicability'] ?? [])] : []), $narrowed['clusters']),
            'differsOn' => array_column($narrowed['differsOn'], 'label'),
        ];
        if ($several) {
            $summary['applicabilityCaveat'] = self::APPLICABILITY_CAVEAT;
        }

        return $summary;
    }

    /**
     * @param list<array{name: string, values: list<string>}> $applicability
     */
    private static function applicabilityLine(array $applicability): string
    {
        $parts = [];
        foreach ($applicability as $entry) {
            $values = \array_slice($entry['values'], 0, self::MAX_APPLICABILITY_VALUES);
            $more = \count($entry['values']) > self::MAX_APPLICABILITY_VALUES ? ', …' : '';
            $parts[] = $entry['name'].' : '.implode(', ', $values).$more;
        }

        return [] === $parts ? 'none listed' : implode(' | ', $parts);
    }

    public function showWebPart(string $categoryLabel, string $reference, string $brand, string $sourceUrl, string $sourceTitle, string $rationale): string
    {
        Assert::stringNotEmpty(trim($reference));
        Assert::regex($sourceUrl, '#^https?://#', 'sourceUrl must be the http(s) URL of the cited source.');

        $this->frames->publishFrame([
            'type' => 'showWebPart',
            'categoryLabel' => trim($categoryLabel),
            'reference' => trim($reference),
            'brand' => trim($brand),
            'sourceUrl' => $sourceUrl,
            'sourceTitle' => trim($sourceTitle),
            'rationale' => trim($rationale),
        ]);

        return 'ok';
    }

    public function setVehicle(int $carId, string $carType, string $make, string $model, string $engine = '', ?string $plate = null): string
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => $carId, 'carType' => $carType, 'make' => $make, 'model' => $model, 'engine' => $engine, 'plate' => $plate]);
        Assert::notNull($vehicle, 'carId must be a positive TecDoc kType and carType PC or CV.');
        $this->vehicle = $vehicle;
        $plate = null === $plate || '' === trim($plate) ? null : strtoupper(trim($plate));

        $this->frames->publishFrame([
            'type' => 'setVehicle',
            'carId' => $vehicle->carId,
            'carType' => $vehicle->carType->value,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'engine' => $vehicle->engine,
            'plate' => $plate,
        ]);

        return 'ok: the finder now shows this vehicle; continue with list_part_categories and show_parts.';
    }

    /**
     * @param list<QuestionOption> $options
     */
    public function askQuestion(string $question, array $options, string $context): string
    {
        Assert::stringNotEmpty(trim($question));
        Assert::allIsInstanceOf($options, QuestionOption::class);
        Assert::countBetween($options, 2, 4);

        $this->frames->publishFrame([
            'type' => 'askQuestion',
            'question' => trim($question),
            'options' => array_map(static fn (QuestionOption $o): array => ['label' => $o->label, 'value' => $o->value], array_values($options)),
            'context' => $context,
        ]);

        return 'ok';
    }
}
