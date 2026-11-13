<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * TecDoc vehicle type: the catalog keys on the string values PC/CV/MC.
 */
enum CarType: string
{
    case PassengerCar = 'PC';
    case CommercialVehicle = 'CV';
    case Motorbike = 'MC';

    /** The accepted wire values, for attribute arguments (JSON schema enum, Assert\Choice) that cannot call a method. */
    public const array VALUES = [self::PassengerCar->value, self::CommercialVehicle->value, self::Motorbike->value];

    public static function fromSivGenre(string $genre): self
    {
        return str_starts_with($genre, 'VP') ? self::PassengerCar : self::CommercialVehicle;
    }
}
