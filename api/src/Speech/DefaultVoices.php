<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;

final readonly class DefaultVoices
{
    public function __construct(
        private string $default,
        private string $english = '',
    ) {
    }

    public function for(Locale $locale): string
    {
        return Locale::En === $locale && '' !== $this->english ? $this->english : $this->default;
    }
}
