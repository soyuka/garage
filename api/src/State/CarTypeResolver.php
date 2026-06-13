<?php

declare(strict_types=1);

namespace App\State;

use App\PartsApi\CarType;

/**
 * @internal shared parsing for providers (query filters) and processors (MCP inputs)
 */
final class CarTypeResolver
{
    public static function fromString(?string $value): CarType
    {
        if (null === $value || '' === $value) {
            return CarType::PassengerCar;
        }

        return CarType::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf('Invalid carType "%s", expected "PC" or "CV".', $value));
    }
}
