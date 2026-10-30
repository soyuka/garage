<?php

declare(strict_types=1);

namespace App\Catalog;

enum CarType: string
{
    case PassengerCar = 'PC';
    case CommercialVehicle = 'CV';
}
