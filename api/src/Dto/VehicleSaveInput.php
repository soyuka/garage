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
}
