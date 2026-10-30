<?php

declare(strict_types=1);

namespace App\Agent\Finder;

/**
 * What the photo intake read off a door-pillar build label (PSA "plaque constructeur"). Untrusted model
 * output either way (from the intake reader or echoed back by the page), bounded like
 * {@see PartPhotoHints} before it reaches a prompt.
 */
final readonly class BuildLabelHints
{
    private const int MAX_CODES = 12;

    private const int MAX_LENGTH = 60;

    private const int MAX_OPR_LENGTH = 20;

    /**
     * @param list<string> $printedCodes
     */
    public function __construct(
        public ?string $opr = null,
        public array $printedCodes = [],
    ) {
    }

    public static function fromPayload(mixed $payload): self
    {
        if (!\is_array($payload)) {
            return new self();
        }

        $codes = \is_array($payload['printedCodes'] ?? null) ? $payload['printedCodes'] : [];
        $codes = array_values(array_unique(array_filter(array_map(static fn (mixed $c): ?string => self::text($c, self::MAX_LENGTH), $codes))));

        return new self(
            opr: self::text($payload['opr'] ?? null, self::MAX_OPR_LENGTH),
            printedCodes: \array_slice($codes, 0, self::MAX_CODES),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->opr && [] === $this->printedCodes;
    }

    /**
     * @return array{opr: ?string, printedCodes: list<string>}
     */
    public function toArray(): array
    {
        return ['opr' => $this->opr, 'printedCodes' => $this->printedCodes];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return '' === $value ? null : mb_substr($value, 0, $max);
    }
}
