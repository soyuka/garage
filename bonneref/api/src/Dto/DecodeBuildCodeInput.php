<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class DecodeBuildCodeInput
{
    #[ApiProperty(description: 'Vehicle make, any case or accent (e.g. "Peugeot", "CITROËN", "DS"). Currently supported: PEUGEOT, CITROEN, DS (PSA).')]
    #[Assert\Length(max: 32)]
    public string $make = '';

    #[ApiProperty(description: 'The raw build code as printed on the car or in a catalogue: an OPR / numéro d\'organisation (4-5 digits, e.g. "10310"), a DAM (OPR + 2-digit plant, e.g. "982488"), or a full APV/PR (e.g. "102638910232" or "10823CJ 20494"). Spaces and a dropped leading zero are fine. Give this OR date.')]
    #[Assert\Length(max: 24)]
    public ?string $code = null;

    #[ApiProperty(description: 'Reverse lookup: a build date as YYYY-MM-DD, answered with the organisation number of that day. Give this OR code.')]
    #[Assert\Length(max: 10)]
    public ?string $date = null;
}
