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
        return $this->fromRaw($this->reader->read($content, $mimeType));
    }

    /**
     * Whitelists and normalises a model's raw answer — shared with the photo intake, which reads the
     * same fields in its single vision call.
     *
     * @param array<string, mixed> $raw
     */
    public function fromRaw(array $raw): RegistrationDocumentExtraction
    {
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
                $warnings[] = RegistrationWarning::VinInvalid;
            }
        }

        // The model's free text never reaches the UI: only known codes, anything else is one generic line.
        foreach (\is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [] as $reported) {
            $known = \is_string($reported) && \in_array($reported, RegistrationWarning::modelCodes(), true) ? RegistrationWarning::tryFrom($reported) : null;
            $warnings[] = $known ?? RegistrationWarning::Incomplete;
        }

        return new RegistrationDocumentExtraction(
            $fields,
            true === ($raw['legible'] ?? false),
            array_values(array_unique(array_map(static fn (RegistrationWarning $w): string => $w->message(), $warnings))),
        );
    }

    /**
     * @param list<RegistrationWarning> $warnings
     */
    private function plate(string $raw, array &$warnings): string
    {
        $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));

        if (1 === preg_match('/^([A-Z]{2})(\d{3})([A-Z]{2})$/', $plate, $m)) {
            return \sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        $warnings[] = RegistrationWarning::PlateFormat;

        return $plate;
    }

    /**
     * @param list<RegistrationWarning> $warnings
     */
    private function date(string $raw, array &$warnings): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d.m.Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if (false !== $date && 0 === ($date::getLastErrors()['warning_count'] ?? 0) && 0 === ($date::getLastErrors()['error_count'] ?? 0)) {
                return $date->format('Y-m-d');
            }
        }

        $warnings[] = RegistrationWarning::DateUnreadable;

        return null;
    }
}
