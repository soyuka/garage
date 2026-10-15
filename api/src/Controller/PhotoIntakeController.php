<?php

declare(strict_types=1);

namespace App\Controller;

use App\PhotoIntake\PhotoIntake;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /photo-intake — sort a finder photo (carte grise, part, other) in one vision call. Same
 * handling as {@see RegistrationDocumentController}: the upload lives only in PHP's temporary file and
 * in memory for the model call, nothing about it is stored or logged.
 */
final class PhotoIntakeController
{
    private const int MAX_BYTES = 10 * 1024 * 1024;

    private const array ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly PhotoIntake $intake,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/photo-intake', name: 'photo_intake', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Send the photo as a multipart "file" field.');
        }

        if (\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() || $file->getSize() > self::MAX_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('The photo is capped at %d bytes.', self::MAX_BYTES));
        }

        if (!$file->isValid()) {
            throw new BadRequestHttpException('The upload failed.');
        }

        $mimeType = $file->getMimeType();
        if (!\in_array($mimeType, self::ALLOWED_TYPES, true)) {
            throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Accepted types: image/jpeg, image/png, image/webp.');
        }

        try {
            $result = $this->intake->classify((string) file_get_contents($file->getPathname()), $mimeType);
        } catch (RegistrationDocumentUnreadable $e) {
            $this->logger->warning('A finder photo could not be read: {reason}', ['reason' => $e->getMessage()]);

            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The photo could not be read.');
        }

        return new JsonResponse($result->toArray());
    }
}
