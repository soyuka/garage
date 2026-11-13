<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\I18n\Locale;

/**
 * The few words the narrowing writes itself around the criterion names and values the catalogue already localises.
 * A map of its own rather than {@see \App\I18n\AgentMessages}: that one is the text said to the user, this one is
 * read by the finder model next to the catalogue's own wording, which it must match.
 */
final readonly class NarrowingWording
{
    private function __construct(
        public string $separator,
        public string $onlyWith,
        public string $noCondition,
        public string $or,
        public string $noneListed,
    ) {
    }

    public static function for(Locale $locale): self
    {
        return match ($locale) {
            Locale::Fr => new self(' : ', 'uniquement avec', 'aucune condition', ' OU ', 'aucune'),
            Locale::En => new self(': ', 'only with', 'no condition', ' OR ', 'none listed'),
        };
    }
}
