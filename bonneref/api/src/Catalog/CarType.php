<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * TecDoc vehicle type: the catalog keys on the string values PC/CV.
 */
enum CarType: string
{
    case PassengerCar = 'PC';
    case CommercialVehicle = 'CV';

    public static function fromSivGenre(string $genre): self
    {
        return str_starts_with($genre, 'VP') ? self::PassengerCar : self::CommercialVehicle;
    }
}
