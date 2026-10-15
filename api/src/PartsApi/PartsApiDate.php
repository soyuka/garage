<?php

declare(strict_types=1);

namespace App\PartsApi;

final class PartsApiDate
{
    /**
     * partsapi serialises dates as RFC 1123 ("Thu, 01 Aug 2002 00:00:00 GMT").
     */
    public static function yearMonth(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value);

        return false === $date ? null : $date->format('Y-m');
    }
}
