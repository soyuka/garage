<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Finder\TurnInputLimits;
use App\Agent\Journal\ModelUsage;
use App\Exception\RateLimitExceededException;
use App\RegistrationDocument\PdfPageCount;
use App\Security\MongoSpendRecorder;
use App\Security\OwnerContext;
use App\Security\OwnerDailyBudget;
use App\Security\OwnerRateLimiter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Lock\LockFactory;

/**
 * The one upload a vision intake reads ({@see PhotoIntakeController}, {@see RegistrationDocumentController}):
 * an image is capped at {@see TurnInputLimits::MAX_IMAGE_BYTES}, a PDF at {@see TurnInputLimits::MAX_DOCUMENT_BYTES}
 * and {@see TurnInputLimits::MAX_DOCUMENT_PAGES} pages ({@see PdfPageCount}),
 * the type is sniffed, and the owner's intake quota is spent only once the upload is valid. The call's estimated
 * cost is then reserved in the owner's daily budget under a per-owner lock, so parallel uploads cannot all pass
 * the check before any of them is charged; the reader settles it to the actual usage.
 */
final readonly class IntakeUpload
{
    // The system prompt and the strict schema, sent with every call (measured ~1.3k tokens, rounded up).
    private const int PROMPT_TOKENS = 1_500;
    // Anthropic bills an image at most ~1.6k tokens once resized to its 1568 px long edge.
    private const int IMAGE_TOKENS = 1_600;
    // A PDF page is billed as its extracted text plus its rendered image (1.5k-3k text tokens per page, docs).
    private const int PDF_PAGE_TOKENS = 4_600;

    public function __construct(
        private OwnerRateLimiter $quota,
        private OwnerDailyBudget $budget,
        private MongoSpendRecorder $spend,
        private LockFactory $locks,
        private OwnerContext $owner,
        #[Autowire('%app.intake.model%')]
        private string $model,
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

        $bytes = (string) file_get_contents($file->getPathname());
        $pages = 'application/pdf' === $mimeType ? PdfPageCount::of($bytes) : 1;
        if (null === $pages || $pages > TurnInputLimits::MAX_DOCUMENT_PAGES) {
            throw new UnprocessableEntityHttpException(\sprintf('A PDF is capped at %d pages.', TurnInputLimits::MAX_DOCUMENT_PAGES));
        }

        try {
            $this->quota->consume();
        } catch (RateLimitExceededException $e) {
            throw new TooManyRequestsHttpException($e->retryAfterSeconds(), 'Photo reading limit reached, retry later.');
        }

        $this->reserve('application/pdf' === $mimeType ? $pages * self::PDF_PAGE_TOKENS : self::IMAGE_TOKENS);

        return ['bytes' => $bytes, 'mimeType' => $mimeType];
    }

    private function reserve(int $fileTokens): void
    {
        parse_str((string) parse_url($this->model, \PHP_URL_QUERY), $options);
        $estimate = new ModelUsage(promptTokens: self::PROMPT_TOKENS + $fileTokens, completionTokens: (int) ($options['max_tokens'] ?? 0));

        $lock = $this->locks->createLock('intake-budget.'.hash('xxh128', $this->owner->current() ?? ''));
        $lock->acquire(true);
        try {
            $this->budget->assertWithin();
            $this->spend->reserve($this->model, $estimate);
        } catch (RateLimitExceededException $e) {
            throw new TooManyRequestsHttpException($e->retryAfterSeconds(), 'Daily budget reached, retry later.');
        } finally {
            $lock->release();
        }
    }
}
