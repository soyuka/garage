<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpToolCollection;
use App\Dto\FillVolumesInput;
use App\State\FillVolumeCollectionProvider;
use App\State\FillVolumesProcessor;

/**
 * A fluid capacity or specification for a vehicle modification (AUTONORMS GetFillVolumes,
 * keyed by the same TecDoc kType). Covers "which oil and how much" in one call:
 * engine oil, gearbox, brake fluid, coolant, A/C refrigerant… with ACEA/API/DOT specs.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/cars/{carId}/fill-volumes',
            uriVariables: ['carId' => new Link(fromClass: Car::class, identifiers: ['id'])],
            provider: FillVolumeCollectionProvider::class,
        ),
    ],
    mcp: [
        'get_fill_volumes' => new McpToolCollection(
            title: 'Fluid Capacities',
            description: 'Get fluid capacities and specifications (engine oil + ACEA/API spec, gearbox oil, brake fluid DOT grade, coolant, A/C refrigerant…) for a vehicle. Use the carId (kType) from lookup_plate or list_cars.',
            input: FillVolumesInput::class,
            processor: FillVolumesProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class FillVolume
{
    /**
     * AUTONORMS titles are Russian only (no lang parameter upstream) — French labels
     * ported from prototype_1. Unknown titles fall back to the original.
     */
    private const LABELS_FR = [
        'Моторное масло с фильтром' => 'Huile moteur (avec filtre)',
        'Спецификация моторного масла' => 'Spéc. huile moteur',
        'Масло коробки переключения передач' => 'Huile boîte de vitesses',
        'Спецификация масла ступенчатой коробки передач' => 'Spéc. huile boîte',
        'Масло дифференциала заднего моста' => 'Huile différentiel arrière',
        'Спецификация масла заднего моста' => 'Spéc. huile pont arrière',
        'Масло вспомогания системы рулевого управления' => 'Huile direction assistée',
        'Спецификация масла гидроусилителя руля' => 'Spéc. direction assistée',
        'Охлаждающая жидкость' => 'Liquide de refroidissement',
        'Спецификация охлаждающей жидкости' => 'Spéc. liquide de refroidissement',
        'Тормозная жидкость при замене' => 'Liquide de frein (vidange)',
        'Спецификация тормозной жидкости' => 'Spéc. liquide de frein',
        'Хладагент для кондиционера' => 'Fluide climatisation',
        'Спецификация хладагента' => 'Spéc. fluide climatisation',
        'Спецификация масла кондиционерa' => 'Spéc. huile climatisation',
    ];

    public function __construct(
        #[ApiProperty(identifier: true, description: 'Position in the upstream list (no natural id)')]
        public int $id,
        #[ApiProperty(description: 'French label (falls back to the original Russian title when unmapped)')]
        public string $label,
        public string $originalTitle,
        #[ApiProperty(description: 'Capacity as a number, null for specification rows')]
        public ?float $volume,
        #[ApiProperty(description: 'liter, gramme… null for specification rows')]
        public ?string $unit,
        #[ApiProperty(description: 'Specification value, e.g. "ACEA E3|E4|E5", "DOT 4", "R134a"')]
        public ?string $info,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows raw GetFillVolumes response
     *
     * @return list<self>
     */
    public static function fromPartsApi(array $rows): array
    {
        $volumes = [];
        $id = 0;
        foreach ($rows as $row) {
            $unit = $row['fillUnit'] ?? null;

            // Every capacity is duplicated with Russian units (л., гр.) — keep the SI rows only.
            if (\in_array($unit, ['л.', 'гр.'], true)) {
                continue;
            }

            $title = (string) ($row['fillTitle'] ?? '');
            $rawVolume = (string) ($row['fillVolume'] ?? '');
            // Specification rows ("Спецификация …") have no unit and carry the spec
            // string ("ACEA E3|…", "DOT 4") in fillVolume.
            $isSpec = null === $unit || '' === $unit;
            $fillInfo = (string) ($row['fillInfo'] ?? '');

            $volumes[] = new self(
                id: $id++,
                label: self::LABELS_FR[$title] ?? $title,
                originalTitle: $title,
                volume: $isSpec || '' === $rawVolume ? null : (float) str_replace(',', '.', $rawVolume),
                unit: $isSpec ? null : (string) $unit,
                info: $isSpec ? ('' === $rawVolume ? null : $rawVolume) : ('' === $fillInfo ? null : $fillInfo),
            );
        }

        return $volumes;
    }
}
