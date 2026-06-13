<?php

declare(strict_types=1);

namespace App\Garage;

/**
 * Garage vehicle segment. `car`/`moto`/`cv` map onto TecDoc coverage; `groundscare`
 * (mowers, snow throwers like the TORO 246-H) is the long-tail segment partsapi does
 * NOT cover — it only ever lives in the garage, filled by the LLM.
 */
enum VehicleType: string
{
    case Car = 'car';
    case Moto = 'moto';
    case CommercialVehicle = 'cv';
    case GroundsCare = 'groundscare';
}
