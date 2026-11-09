<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Finder\TurnInputLimits;
use App\Exception\RateLimitExceededException;
use App\Security\OwnerDailyBudget;
use App\Security\OwnerRateLimiter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The one upload a vision intake reads ({@see PhotoIntakeController}, {@see RegistrationDocumentController}):
 * an image is capped at {@see TurnInputLimits::MAX_IMAGE_BYTES}, a PDF at {@see TurnInputLimits::MAX_DOCUMENT_BYTES},
 * the type is sniffed, and the owner's intake quota is spent only once the upload is valid.
 */
final readonly class IntakeUpload
{
    public function __construct(
        private OwnerRateLimiter $quota,
        private OwnerDailyBudget $budget,
    ) {
    }

    /**
     * @param list<string> $types
     *
     * @return array{bytes: string, mimeType: string}
     */
    public function read(Request $request, array $types, string $what): array
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException(\sprintf('Send the %s as a multipart "file" field.', $what));
        }

        $size = (int) $file->getSize();
        if (\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() || $size > TurnInputLimits::MAX_DOCUMENT_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('The %s is capped at %d bytes.', $what, TurnInputLimits::MAX_DOCUMENT_BYTES));
        }

        if (!$file->isValid()) {
            throw new BadRequestHttpException('The upload failed.');
        }

        $mimeType = (string) $file->getMimeType();
        if (!\in_array($mimeType, $types, true)) {
            throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Accepted types: '.implode(', ', $types).'.');
        }

        if ('application/pdf' !== $mimeType && $size > TurnInputLimits::MAX_IMAGE_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('An image is capped at %d bytes.', TurnInputLimits::MAX_IMAGE_BYTES));
        }

        try {
            $this->budget->assertWithin();
        } catch (RateLimitExceededException $e) {
            throw new TooManyRequestsHttpException($e->retryAfterSeconds(), 'Daily budget reached, retry later.');
        }

        try {
            $this->quota->consume();
        } catch (RateLimitExceededException $e) {
            throw new TooManyRequestsHttpException($e->retryAfterSeconds(), 'Photo reading limit reached, retry later.');
        }

        return ['bytes' => (string) file_get_contents($file->getPathname()), 'mimeType' => $mimeType];
    }
}
