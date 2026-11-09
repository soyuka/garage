<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Vehicle;
use App\Exception\InvalidPlateException;
use App\Exception\RateLimitExceededException;
use App\Vehicle\VehicleLookup;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<Vehicle>
 */
final readonly class VehicleProvider implements ProviderInterface
{
    public function __construct(private VehicleLookup $vehicleLookup)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Vehicle
    {
        try {
            return $this->vehicleLookup->byPlate((string) $uriVariables['plate']);
        } catch (InvalidPlateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (RateLimitExceededException $e) {
            throw new TooManyRequestsHttpException($e->retryAfterSeconds(), 'Plate lookup limit reached, retry later.', $e);
        }
    }
}
