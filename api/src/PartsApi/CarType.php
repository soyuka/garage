<?php

declare(strict_types=1);

namespace App\PartsApi;

/**
 * TecDoc vehicle type. partsapi.ru expects the string values 'PC'/'CV' —
 * anything else ('PassengerCar', integers…) silently returns an empty list.
 */
enum CarType: string
{
    case PassengerCar = 'PC';
    case CommercialVehicle = 'CV';

    /**
     * SIV "genre" (carte grise J.1): VP* → passenger car, anything else (CTTE, CAM…) → commercial.
     */
    public static function fromSivGenre(string $genre): self
    {
        return str_starts_with($genre, 'VP') ? self::PassengerCar : self::CommercialVehicle;
    }
}
