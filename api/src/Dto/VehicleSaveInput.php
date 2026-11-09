<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /garage/vehicles/save — the finder's "Enregistrer le véhicule".
 */
final class VehicleSaveInput
{
    #[ApiProperty(description: 'An existing vehicle @id (e.g. "/garage/vehicles/01J…"): that row is returned, its missing plate/carId/vin filled in. Resolves an ambiguous_match.')]
    #[Assert\Length(max: 200)]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "PEUGEOT". Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "307". Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $model = null;

    #[ApiProperty(description: 'TecDoc kType of the vehicle, when known.')]
    #[Assert\Positive]
    public ?int $carId = null;

    #[ApiProperty(description: 'Registration plate. The vehicle identity: the same plate returns the stored vehicle, another plate is another car.')]
    #[Assert\Length(max: 20)]
    public ?string $plate = null;

    #[ApiProperty(description: 'VIN, when known.')]
    #[Assert\Length(max: 17)]
    public ?string $vin = null;

    #[ApiProperty(description: 'Set true to create a new vehicle even though candidates were surfaced.')]
    public bool $confirmNew = false;

    #[ApiProperty(description: 'Carte grise fields read by the finder: recorded as facts on the saved (or matched) vehicle, source "carte grise", in the same call. Only non-blank fields are written.')]
    #[Assert\Valid]
    public ?RegistrationFactsInput $registration = null;

    #[ApiProperty(description: 'The car\'s code de fabrication (PSA OPR / DAM / APV) as read by the finder: decoded server-side for the vehicle make and recorded as facts organisation_number and build_date, source "code de fabrication". An unsupported make or an unreadable code writes nothing.')]
    #[Assert\Length(max: 16)]
    public ?string $buildCode = null;
}
