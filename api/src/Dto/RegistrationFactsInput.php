<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The carte grise fields the finder confirmed, recorded as vehicle facts alongside the save. Section C
 * (holder) is deliberately absent: it is never read, never stored.
 */
final class RegistrationFactsInput
{
    #[ApiProperty(description: 'Field E, the VIN.')]
    #[Assert\Length(max: 17)]
    public ?string $vin = null;

    #[ApiProperty(description: 'Field B, first registration date (ISO).')]
    #[Assert\Length(max: 32)]
    public ?string $firstRegistration = null;

    #[ApiProperty(description: 'Field D.2.1, national type code (CNIT).')]
    #[Assert\Length(max: 32)]
    public ?string $cnit = null;

    #[ApiProperty(description: 'Field P.1, displacement in cm3.')]
    #[Assert\Length(max: 16)]
    public ?string $displacement = null;

    #[ApiProperty(description: 'Field P.2, net power in kW.')]
    #[Assert\Length(max: 16)]
    public ?string $powerKw = null;

    #[ApiProperty(description: 'Field P.3, energy code.')]
    #[Assert\Length(max: 32)]
    public ?string $energy = null;

    #[ApiProperty(description: 'Field P.6, fiscal power in CV.')]
    #[Assert\Length(max: 16)]
    public ?string $fiscalPower = null;
}
