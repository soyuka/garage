<?php

declare(strict_types=1);

namespace App\Catalog;

enum CarType: string
{
    case PassengerCar = 'PC';
    case CommercialVehicle = 'CV';
    case Motorbike = 'MC';

    /** The accepted wire values, for attribute arguments (JSON schema enum, Assert\Choice) that cannot call a method. */
    public const array VALUES = [self::PassengerCar->value, self::CommercialVehicle->value, self::Motorbike->value];
}
