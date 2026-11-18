<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\OwnedVehicleLocator;
use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentRecorder;
use App\Documentation\VehicleDocumentView;
use App\Security\OwnerRateLimit;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /garage/vehicles/{id}/documents — multipart `file` (PDF/JPEG/PNG, MIME sniffed from the bytes)
 * plus `kind`, and optionally `title`, `sourceRef`, `plannedTaskId`.
 */
final readonly class UploadVehicleDocumentController
{
    public function __construct(
        private OwnedVehicleLocator $locator,
        private VehicleDocumentRecorder $recorder,
        private VehicleDocumentView $view,
        private OwnerRateLimit $rateLimit,
        private RateLimiterFactoryInterface $uploadLimiter,
        private RateLimiterFactoryInterface $uploadGlobalLimiter,
    ) {
    }

    #[Route('/garage/vehicles/{id}/documents', name: 'vehicle_document_upload', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $vehicle = $this->locator->require($id);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('A file is required in the multipart "file" field.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException(\sprintf('Upload failed: %s.', $file->getErrorMessage()));
        }

        $kind = VehicleDocumentKind::tryFrom((string) $request->request->get('kind'))
            ?? throw new UnprocessableEntityHttpException(\sprintf('"kind" must be one of: %s.', implode(', ', array_map(static fn (VehicleDocumentKind $kind): string => $kind->value, VehicleDocumentKind::cases()))));

        $this->rateLimit->consume($this->uploadLimiter, 'Upload', $this->uploadGlobalLimiter);

        try {
            $document = $this->recorder->record(
                $vehicle,
                $file->getPathname(),
                $file->getClientOriginalName(),
                $kind,
                title: $this->optional($request, 'title'),
                sourceRef: $this->optional($request, 'sourceRef'),
                plannedTaskId: $this->optional($request, 'plannedTaskId'),
            );
        } catch (InvalidVehicleDocumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse($this->view->toArray($document), Response::HTTP_CREATED);
    }

    private function optional(Request $request, string $field): ?string
    {
        $value = trim((string) $request->request->get($field));

        return '' === $value ? null : $value;
    }
}
