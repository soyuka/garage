<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

use App\Vin\VinDecoder;

final readonly class RegistrationDocumentExtractor
{
    public function __construct(
        private RegistrationDocumentReader $reader,
        private VinDecoder $vinDecoder,
    ) {
    }

    /**
     * @throws RegistrationDocumentUnreadable
     */
    public function extract(string $content, string $mimeType): RegistrationDocumentExtraction
    {
        $raw = $this->reader->read($content, $mimeType);

        $fields = [];
        foreach (AnthropicRegistrationDocumentReader::FIELDS as $code) {
            $value = $raw[$code] ?? null;
            $value = \is_string($value) || \is_int($value) || \is_float($value) ? trim((string) $value) : '';
            $fields[$code] = '' === $value ? null : $value;
        }

        $warnings = [];

        if (null !== $fields['A']) {
            $fields['A'] = $this->plate($fields['A'], $warnings);
        }
        if (null !== $fields['B']) {
            $fields['B'] = $this->date($fields['B'], $warnings);
        }
        if (null !== $fields['E']) {
            $vin = $this->vinDecoder->decode($fields['E']);
            $fields['E'] = $vin->vin;
            if (!$vin->valid) {
                $warnings[] = 'E: not a valid VIN, check the chassis number.';
            }
        }

        $modelWarnings = array_filter($raw['warnings'] ?? [], \is_string(...));

        return new RegistrationDocumentExtraction($fields, true === ($raw['legible'] ?? false), [...$warnings, ...array_values($modelWarnings)]);
    }

    /**
     * @param list<string> $warnings
     */
    private function plate(string $raw, array &$warnings): string
    {
        $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));

        if (1 === preg_match('/^([A-Z]{2})(\d{3})([A-Z]{2})$/', $plate, $m)) {
            return \sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        $warnings[] = 'A: not in the AA-123-AA format.';

        return $plate;
    }

    /**
     * @param list<string> $warnings
     */
    private function date(string $raw, array &$warnings): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d.m.Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if (false !== $date && 0 === ($date::getLastErrors()['warning_count'] ?? 0) && 0 === ($date::getLastErrors()['error_count'] ?? 0)) {
                return $date->format('Y-m-d');
            }
        }

        $warnings[] = 'B: not a readable date.';

        return null;
    }
}
