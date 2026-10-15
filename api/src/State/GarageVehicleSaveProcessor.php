<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\VehicleSaveInput;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use App\Mercure\DomainPublisher;
use Doctrine\ODM\MongoDB\DocumentManager;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /garage/vehicles/save — the finder's "Enregistrer le véhicule". The plate-aware dedup lives in
 * {@see VehicleWriteResolver::resolveForSave()}; here only the HTTP semantics: 201 + the vehicle when
 * created, 200 + the vehicle when matched, 409 + candidates (same body as POST /garage/planned-tasks),
 * 404 on an unknown vehicle IRI, 422 on invalid input.
 *
 * @implements ProcessorInterface<VehicleSaveInput, GarageVehicle|Response>
 */
final class GarageVehicleSaveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleWriteResolver $resolver,
        private readonly VehicleIriResolver $iriResolver,
        private readonly VehicleSummaryView $summaryView,
        private readonly DocumentManager $documentManager,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle|Response
    {
        $make = self::text($data->make);
        $model = self::text($data->model);
        $plate = self::text($data->plate);
        $plate = null === $plate ? null : strtoupper($plate);
        $vin = self::text($data->vin);
        $vin = null === $vin ? null : strtoupper($vin);

        $vehicleId = null;
        if (null !== self::text($data->vehicle)) {
            $resolved = $this->iriResolver->resolve((string) $data->vehicle);
            if ($resolved instanceof CallToolResult) {
                $reason = $resolved->structuredContent['reason'];
                throw 'not_found' === $resolved->structuredContent['status']
                    ? new NotFoundHttpException($reason)
                    : new UnprocessableEntityHttpException($reason);
            }
            $vehicleId = $resolved;
        } elseif (null !== $guard = $this->iriResolver->requireIdentity($make, $model)) {
            throw new UnprocessableEntityHttpException($guard->structuredContent['reason']);
        }

        $resolution = $this->resolver->resolveForSave($vehicleId, $make, $model, VehicleType::Car, $data->carId, $plate, $vin, $data->confirmNew, 'user');

        if ('not_found' === $resolution->status) {
            throw new NotFoundHttpException(\sprintf('No vehicle %s in the garage.', $resolution->vehicleId));
        }

        if ('ambiguous' === $resolution->status) {
            return new JsonResponse([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-send with vehicle set to the matching candidate\'s @id, or confirmNew:true to save a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $resolution->candidates),
            ], Response::HTTP_CONFLICT);
        }

        $vehicle = $resolution->vehicle;
        \assert(null !== $vehicle);
        $this->documentManager->flush();
        $this->publisher->publishVehicle($vehicle, $resolution->created ? 'created' : 'updated');

        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            // Read by API Platform's RespondProcessor: the operation's 201 only holds for a creation.
            $request->attributes->set('_api_response_status', $resolution->created ? Response::HTTP_CREATED : Response::HTTP_OK);
        }

        return GarageVehicle::fromEntity($vehicle);
    }

    private static function text(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
