<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Catalog\CarType;
use App\State\KnownCarProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the vehicle index already knows of a carId (kType), so a page opened on a bare carId can
 * name the car without a plate lookup or an upstream call. 404 when the index never saw it.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/cars/{carId}/identity',
            uriVariables: ['carId'],
            provider: KnownCarProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type of the carId: PC (default), CV or MC',
                    schema: ['type' => 'string', 'enum' => CarType::VALUES, 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: CarType::VALUES)],
                ),
            ],
        ),
    ],
)]
final class KnownCar
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $carId,
        public string $carType,
        public ?string $make,
        public ?string $model,
        public ?string $engine,
        public ?string $fullName,
        public ?string $yearFrom,
        public ?string $yearTo,
    ) {
    }
}
