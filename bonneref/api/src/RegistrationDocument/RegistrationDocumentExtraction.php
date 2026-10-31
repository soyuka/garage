<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

/**
 * The only shape that leaves the endpoint. Holder fields (C.*) have no slot here by design.
 */
final readonly class RegistrationDocumentExtraction
{
    /**
     * @param array<string, string|null> $fields   keyed by carte grise field code, see {@see AnthropicRegistrationDocumentReader::FIELDS}
     * @param list<string>               $warnings
     */
    public function __construct(
        public array $fields,
        public bool $legible,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (AnthropicRegistrationDocumentReader::FIELDS as $code) {
            $out[$code] = $this->fields[$code] ?? null;
        }

        return $out + ['legible' => $this->legible, 'warnings' => $this->warnings];
    }
}
