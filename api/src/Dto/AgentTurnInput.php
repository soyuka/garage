<?php

declare(strict_types=1);

namespace App\Dto;

use App\Agent\Ui\DashboardChannel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /agent/turn. Every field reaches the model's context, so each is bounded: the dashboard's
 * pendingContext is a card headline + finding (≤ 200) + its option labels, summary is one vehicle line.
 *
 * The instruction may be blank only when files ride along (a pasted photo with no text); the controller
 * validates {@see self::WITHOUT_FILES} when none do, since the payload mapper never sees `files[]`.
 */
final class AgentTurnInput
{
    public const string WITHOUT_FILES = 'without_files';

    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', groups: [self::WITHOUT_FILES])]
        #[Assert\Length(max: 2000, normalizer: 'trim')]
        public string $instruction = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: DashboardChannel::SESSION_ID_PATTERN, message: 'sessionId must be 1-128 of [A-Za-z0-9_-]')]
        public string $sessionId = '',
        #[Assert\Length(max: 200)]
        public ?string $selectedId = null,
        #[Assert\Length(max: 500)]
        public ?string $summary = null,
        #[Assert\Length(max: 2000)]
        public ?string $pendingContext = null,
    ) {
    }
}
