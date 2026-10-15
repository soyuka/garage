<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\PartPhotoHints;
use App\RegistrationDocument\RegistrationDocumentExtractor;
use App\RegistrationDocument\RegistrationDocumentUnreadable;

/**
 * Sorts a finder photo with ONE vision call: a carte grise (read with the registration-document rules:
 * section C never extracted, the file never stored), a part (printed references, brand and family
 * guess for the agent), a door-pillar build label (the printed codes, the PSA OPR among them), or anything
 * else.
 */
final readonly class PhotoIntake
{
    public function __construct(
        private PhotoIntakeReader $reader,
        private RegistrationDocumentExtractor $registration,
    ) {
    }

    /**
     * @throws RegistrationDocumentUnreadable
     */
    public function classify(string $content, string $mimeType): PhotoIntakeResult
    {
        $raw = $this->reader->read($content, $mimeType);

        return match (PhotoKind::tryFrom(\is_string($raw['kind'] ?? null) ? $raw['kind'] : '') ?? PhotoKind::Other) {
            PhotoKind::CarteGrise => new PhotoIntakeResult(PhotoKind::CarteGrise, registration: $this->registration->fromRaw($raw)),
            PhotoKind::Part => new PhotoIntakeResult(PhotoKind::Part, part: PartPhotoHints::fromPayload($raw['part'] ?? null)),
            PhotoKind::BuildLabel => new PhotoIntakeResult(PhotoKind::BuildLabel, buildLabel: BuildLabelHints::fromPayload($raw['buildLabel'] ?? null)),
            PhotoKind::Other => new PhotoIntakeResult(PhotoKind::Other),
        };
    }
}
