<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\McpTool;
use App\Capability\Capability;
use App\Catalog\CarType;
use App\Dto\PlateInput;
use App\State\VehicleLookupProcessor;
use App\State\VehicleProvider;

/**
 * A vehicle registered in France, identified by its registration plate (SIV data).
 */
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/vehicles/{plate}', provider: VehicleProvider::class),
    ],
    mcp: [
        'lookup_plate' => new McpTool(
            title: 'Plate Lookup',
            description: 'Look up a French vehicle by its registration plate (plaque d\'immatriculation). Returns identity, technical data and the TecDoc identifiers (carId/kType, carType) needed by the parts and fluids tools.',
            input: PlateInput::class,
            validate: true,
            processor: VehicleLookupProcessor::class,
            // Hits the external SIV registry → open world; read-only from the caller's view.
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
            // Not advertised without API_PLAQUE_TOKEN. The HTTP Get stays: mpa reads its 501 as
            // "plate lookup is not configured on the catalogue server".
            extraProperties: ['requires' => Capability::PlateLookup],
        ),
    ],
)]
final class Vehicle
{
    #[ApiProperty(identifier: true, description: 'Normalized plate (uppercase, no separators), e.g. XC302ZC')]
    public string $plate;

    public string $country = 'FR';

    public ?string $make = null;
    public ?string $model = null;
    #[ApiProperty(description: 'TecDoc model label, e.g. "DAILY III Van"')]
    public ?string $modelEn = null;
    public ?string $version = null;

    public ?string $vin = null;
    #[ApiProperty(description: 'Manufacturer engine code, e.g. F1AE0481B')]
    public ?string $engineCode = null;

    #[ApiProperty(description: 'First registration date (YYYY-MM-DD)')]
    public ?string $firstRegistration = null;

    public ?string $energy = null;
    public ?string $bodyType = null;
    #[ApiProperty(description: 'SIV genre (carte grise J.1): VP = passenger car, CTTE/CAM… = commercial')]
    public ?string $genre = null;

    public ?int $fiscalPower = null;
    public ?int $powerKw = null;
    public ?int $powerHp = null;
    public ?int $engineCapacityCcm = null;
    public ?int $cylinders = null;
    #[ApiProperty(description: 'M = manual, A = automatic')]
    public ?string $gearbox = null;
    public ?int $seats = null;
    public ?int $weightKg = null;

    #[ApiProperty(description: 'TecDoc kType — the vehicle modification id. Key for list_part_categories and list_articles.')]
    public ?int $carId = null;

    #[ApiProperty(description: 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle). Required alongside carId by the parts tools.')]
    public ?string $carType = null;

    #[ApiProperty(description: 'TecDoc manufacturer id')]
    public ?int $tecdocManuId = null;

    #[ApiProperty(description: 'TecDoc model id')]
    public ?int $tecdocModelId = null;

    /**
     * @param array<string, mixed> $data SIV payload from apiplaqueimmatriculation.com
     */
    public static function fromSiv(array $data): self
    {
        $vehicle = new self();
        $vehicle->plate = (string) ($data['immat'] ?? '');
        $vehicle->country = (string) ($data['pays'] ?? 'FR');
        $vehicle->make = self::str($data['marque'] ?? null);
        $vehicle->model = self::str($data['modele'] ?? null);
        $vehicle->modelEn = self::str($data['modele_en'] ?? null);
        $vehicle->version = self::str($data['version'] ?? null);
        $vehicle->vin = self::str($data['vin'] ?? null);
        $vehicle->engineCode = self::str($data['code_moteur'] ?? null);
        $vehicle->firstRegistration = self::str($data['date1erCir_us'] ?? null);
        $vehicle->energy = self::str($data['energieNGC'] ?? null);
        $vehicle->bodyType = self::str($data['carrosserieCG'] ?? null);
        $vehicle->genre = self::str($data['genreVCGNGC'] ?? null);
        $vehicle->fiscalPower = self::int($data['puisFisc'] ?? null);
        $vehicle->powerKw = self::int($data['puisFiscReelKW'] ?? null);
        $vehicle->powerHp = self::int($data['puisFiscReelCH'] ?? null);
        $vehicle->engineCapacityCcm = self::int($data['ccm'] ?? null);
        $vehicle->cylinders = self::int($data['cylindres'] ?? null);
        $vehicle->gearbox = self::str($data['boite_vitesse'] ?? null);
        $vehicle->seats = self::int($data['nr_passagers'] ?? null);
        $vehicle->weightKg = self::int($data['ptac'] ?? null);
        $vehicle->carId = self::int($data['tecdoc_car_id'] ?? null);
        $vehicle->carType = CarType::fromSivGenre((string) ($data['genreVCGNGC'] ?? ''))->value;
        $vehicle->tecdocManuId = self::int($data['tecdoc_manu_id'] ?? null);
        $vehicle->tecdocModelId = self::int($data['tecdoc_model_id'] ?? null);

        return $vehicle;
    }

    private static function str(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /**
     * SIV numbers come with units ("71 KW", "2287 CM3") — keep the leading integer.
     */
    private static function int(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return preg_match('/^\s*(\d+)/', (string) $value, $m) ? (int) $m[1] : null;
    }
}
