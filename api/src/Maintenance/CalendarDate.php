<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * A `YYYY-MM-DD` day from the API, at midnight. Inputs carry #[Assert\Date], so a refusal here means a
 * caller skipped validation; `new \DateTimeImmutable()` would instead accept "tomorrow" or throw a 500.
 */
final class CalendarDate
{
    public static function parse(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(\sprintf('Invalid date "%s"; expected YYYY-MM-DD.', $date));
        }

        return $parsed;
    }

    public static function parseNullable(?string $date): ?\DateTimeImmutable
    {
        return null === $date ? null : self::parse($date);
    }
}
