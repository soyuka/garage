<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Finder\TurnInputLimits;
use App\RegistrationDocument\RegistrationDocumentExtractor;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
final readonly class RegistrationDocumentController
{
    public function __construct(
        private RegistrationDocumentExtractor $extractor,
        private LoggerInterface $logger,
        private IntakeUpload $upload,
    ) {
    }

    #[Route('/registration-documents/extract', name: 'registration_document_extract', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $upload = $this->upload->read($request, [...TurnInputLimits::IMAGE_TYPES, 'application/pdf'], 'document');

        try {
            $extraction = $this->extractor->extract($upload['bytes'], $upload['mimeType']);
        } catch (RegistrationDocumentUnreadable $e) {
            $this->logger->warning('A registration document could not be read: {reason}', ['reason' => $e->getMessage()]);

            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The document could not be read.');
        }

        return new JsonResponse($extraction->toArray());
    }
}
