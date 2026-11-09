<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Catalog\CarType;
use App\Catalog\PartsCatalog;
use App\Siv\SivClient;

/**
 * The vehicle a finder turn carries, rebuilt from the catalogue: the caller only names the kType (and
 * the plate and build code it holds), every name reaching the prompt is the catalogue's.
 */
final readonly class FinderVehicleResolver
{
    public function __construct(private PartsCatalog $catalog)
    {
    }

    /**
     * @throws InvalidTurnInput
     */
    public function resolve(mixed $payload): FinderVehicle
    {
        $carId = \is_array($payload) ? ($payload['carId'] ?? null) : null;
        $carType = CarType::tryFrom(\is_array($payload) && \is_string($payload['carType'] ?? null) ? $payload['carType'] : CarType::PassengerCar->value);
        if (!\is_int($carId) || $carId <= 0 || null === $carType) {
            throw new InvalidTurnInput('vehicle needs a carId (and a PC/CV carType); omit it to let the agent identify the car');
        }

        return $this->forCar($carId, $carType, self::plate($payload['plate'] ?? null), self::organisationNumber($payload['buildCode'] ?? null));
    }

    /**
     * @throws InvalidTurnInput
     */
    public function forCar(int $carId, CarType $carType, ?string $plate = null, ?string $organisationNumber = null): FinderVehicle
    {
        $identity = $this->catalog->carIdentity($carId, $carType);
        if (null === $identity) {
            throw new InvalidTurnInput(\sprintf('carId %d (%s) is not in the catalogue', $carId, $carType->value));
        }

        return new FinderVehicle(
            carId: $carId,
            carType: $carType,
            make: $identity['make'],
            model: '' === $identity['model'] ? null : $identity['model'],
            engine: '' === $identity['engine'] ? null : $identity['engine'],
            plate: $plate,
            organisationNumber: $organisationNumber,
        );
    }

    /**
     * @throws InvalidTurnInput
     */
    public static function plate(mixed $raw): ?string
    {
        if (null === $raw || (\is_string($raw) && '' === trim($raw))) {
            return null;
        }
        $plate = \is_string($raw) && mb_strlen($raw) <= 20 ? SivClient::normalizePlate($raw) : '';
        if (1 === preg_match(SivClient::SIV_PLATE, $plate, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if (1 === preg_match(SivClient::FNI_PLATE, $plate)) {
            return $plate;
        }

        throw new InvalidTurnInput('vehicle.plate is not a French registration plate');
    }

    private static function organisationNumber(mixed $buildCode): ?string
    {
        $number = \is_array($buildCode) ? ($buildCode['organisationNumber'] ?? null) : null;

        return \is_string($number) && 1 === preg_match('/^\d{4,5}$/', $number) ? $number : null;
    }
}
