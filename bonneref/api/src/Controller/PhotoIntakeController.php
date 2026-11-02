<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Finder\TurnInputLimits;
use App\PhotoIntake\IntakeReadings;
use App\PhotoIntake\PhotoIntake;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use App\Security\OwnerContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /photo-intake — sort a finder photo (carte grise, part, other) in one vision call. Same
 * handling as {@see RegistrationDocumentController}: the upload lives only in PHP's temporary file and
 * in memory for the model call, and is never stored or logged. What was read off a part or a build
 * label is kept an hour under an id ({@see IntakeReadings}) for the turn that follows.
 */
final readonly class PhotoIntakeController
{
    public function __construct(
        private PhotoIntake $intake,
        private LoggerInterface $logger,
        private IntakeUpload $upload,
        private IntakeReadings $readings,
        private OwnerContext $owner,
    ) {
    }

    #[Route('/photo-intake', name: 'photo_intake', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $upload = $this->upload->read($request, TurnInputLimits::IMAGE_TYPES, 'photo');

        try {
            $result = $this->intake->classify($upload['bytes'], $upload['mimeType']);
        } catch (RegistrationDocumentUnreadable $e) {
            $this->logger->warning('A finder photo could not be read: {reason}', ['reason' => $e->getMessage()]);

            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The photo could not be read.');
        }

        // The page sends this id with the turn; the reading itself never travels back as prompt text.
        return new JsonResponse($result->toArray() + ['intakeId' => $this->readings->keep($this->owner->require(), $result)]);
    }
}
