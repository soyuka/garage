<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\McpTool;
use App\Dto\VinInput;
use App\State\DecodeVinProcessor;
use App\State\VinDecodingProvider;

#[ApiResource(
    operations: [
        new Get(uriTemplate: '/vins/{vin}', provider: VinDecodingProvider::class),
    ],
    mcp: [
        'decode_vin' => new McpTool(
            title: 'Decode VIN',
            description: 'Decode the structure of a 17-character VIN (ISO 3779) offline: WMI (manufacturer + country code), VDS, VIS, manufacturer name, region and country, a model year, and the check digit. Returns valid=false with an error for a malformed VIN instead of failing. CAVEATS: a VIN does NOT give a TecDoc kType — the registration plate (lookup_plate) remains the way to a kType, carId and the parts/fluids tools. The model year (position 10) is only trusted for North American VINs, where it is mandated, and is null otherwise. Outside North America position 10 is unreliable (a 2004 Peugeot 307 reads as 2008), so modelYearCodeCandidates is a hint at most; the carte grise B field or the plate lookup (lookup_plate) give the real first-registration date. checksumValid is only meaningful for North American VINs (mandatory check digit); it is null for the rest of the world. Read-only, offline.',
            input: VinInput::class,
            structuredContent: false,
            processor: DecodeVinProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class VinDecoding
{
    #[ApiProperty(identifier: true, description: 'Normalised VIN (uppercase, separators removed)')]
    public string $vin;

    public bool $valid = false;

    #[ApiProperty(description: 'Why the VIN is invalid; null when valid')]
    public ?string $error = null;

    #[ApiProperty(description: 'World Manufacturer Identifier, first 3 characters')]
    public ?string $wmi = null;

    #[ApiProperty(description: 'Vehicle Descriptor Section, characters 4-9')]
    public ?string $vds = null;

    #[ApiProperty(description: 'Vehicle Identifier Section, characters 10-17')]
    public ?string $vis = null;

    public ?string $manufacturer = null;
    public ?string $region = null;
    public ?string $country = null;

    #[ApiProperty(description: 'Model year from VIN position 10; only set for North American VINs, where it is mandated. Null elsewhere.')]
    public ?int $modelYear = null;

    /** @var list<int> */
    #[ApiProperty(description: 'Years position 10 could stand for on a non-North-American VIN. A hint only: makers there do not reliably encode the model year.')]
    public array $modelYearCodeCandidates = [];

    #[ApiProperty(description: 'Check digit result; only mandatory in North America, null elsewhere')]
    public ?bool $checksumValid = null;
}
