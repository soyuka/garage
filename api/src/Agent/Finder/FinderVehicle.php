<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\PartsApi\CarType;

/**
 * The vehicle the finder already identified (plate → kType). Sent with every finder turn so the agent
 * never re-resolves what the page knows, and so show_parts narrows against the very car on screen
 * rather than a carId the model could mistype.
 */
final readonly class FinderVehicle
{
    public function __construct(
        public int $carId,
        public CarType $carType = CarType::PassengerCar,
        public ?string $make = null,
        public ?string $model = null,
        public ?string $engine = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $carId = $payload['carId'] ?? null;
        if (!\is_int($carId) || $carId <= 0) {
            return null;
        }

        $text = static fn (string $key): ?string => \is_string($payload[$key] ?? null) && '' !== trim($payload[$key]) ? trim($payload[$key]) : null;

        $carType = CarType::tryFrom($text('carType') ?? CarType::PassengerCar->value);
        if (null === $carType) {
            return null;
        }

        return new self(
            carId: $carId,
            carType: $carType,
            make: $text('make'),
            model: $text('model'),
            engine: $text('engine'),
        );
    }
}
