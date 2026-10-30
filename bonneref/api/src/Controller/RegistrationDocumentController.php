<?php

declare(strict_types=1);

namespace App\Controller;

use App\RegistrationDocument\RegistrationDocumentExtractor;
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
 * POST /registration-documents/extract — read the technical fields of a carte grise photo.
 *
 * The upload lives only in PHP's temporary upload file and in memory for the model call: it is
 * never stored, and neither the document nor the model's answer is logged. Holder data (section C)
 * is not requested and has no slot in {@see \App\RegistrationDocument\RegistrationDocumentExtraction}.
 * Authenticated (security.yaml) because every call spends model tokens.
 */
final class RegistrationDocumentController
{
    private const int MAX_BYTES = 10 * 1024 * 1024;

    private const array ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(
        private readonly RegistrationDocumentExtractor $extractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/registration-documents/extract', name: 'registration_document_extract', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Send the document as a multipart "file" field.');
        }

        if (\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() || $file->getSize() > self::MAX_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('The document is capped at %d bytes.', self::MAX_BYTES));
        }

        if (!$file->isValid()) {
            throw new BadRequestHttpException('The upload failed.');
        }

        $mimeType = $file->getMimeType();
        if (!\in_array($mimeType, self::ALLOWED_TYPES, true)) {
            throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Accepted types: image/jpeg, image/png, image/webp, application/pdf.');
        }

        try {
            $extraction = $this->extractor->extract((string) file_get_contents($file->getPathname()), $mimeType);
        } catch (RegistrationDocumentUnreadable $e) {
            $this->logger->warning('A registration document could not be read: {reason}', ['reason' => $e->getMessage()]);

            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The document could not be read.');
        }

        return new JsonResponse($extraction->toArray());
    }
}
