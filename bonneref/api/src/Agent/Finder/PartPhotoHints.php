<?php

declare(strict_types=1);

namespace App\Agent\Finder;

/**
 * What the photo intake read off a part photo. Untrusted model output either way (from the intake
 * reader or echoed back by the page), so every field is bounded before it reaches a prompt.
 */
final readonly class PartPhotoHints
{
    private const int MAX_REFERENCES = 8;

    private const int MAX_LENGTH = 60;

    /**
     * @param list<string> $printedReferences
     */
    public function __construct(
        public ?string $familyGuess = null,
        public array $printedReferences = [],
        public ?string $brandGuess = null,
    ) {
    }

    public static function fromPayload(mixed $payload): self
    {
        if (!\is_array($payload)) {
            return new self();
        }

        $refs = \is_array($payload['printedReferences'] ?? null) ? $payload['printedReferences'] : [];
        $refs = array_values(array_unique(array_filter(array_map(self::text(...), $refs))));

        return new self(
            familyGuess: self::text($payload['familyGuess'] ?? null),
            printedReferences: \array_slice($refs, 0, self::MAX_REFERENCES),
            brandGuess: self::text($payload['brandGuess'] ?? null),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->familyGuess && [] === $this->printedReferences && null === $this->brandGuess;
    }

    /**
     * @return array{familyGuess: ?string, printedReferences: list<string>, brandGuess: ?string}
     */
    public function toArray(): array
    {
        return ['familyGuess' => $this->familyGuess, 'printedReferences' => $this->printedReferences, 'brandGuess' => $this->brandGuess];
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return '' === $value ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }
}
