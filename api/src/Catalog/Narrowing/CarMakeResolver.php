<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\PartsApi\CarType;

interface CarMakeResolver
{
    public function make(int $carId, CarType $carType): ?string;
}
