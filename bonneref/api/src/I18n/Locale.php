<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * The languages the app speaks. French is the default and stays the default — this is a French
 * garage (PRODUCT.md § "the room") and English is a second catalog beside it, never a replacement.
 *
 * The value is the primary subtag, which is deliberately the same token three different consumers
 * want: Symfony's `$request->getLocale()`, the dashboard's `mpa.locale`, and ElevenLabs'
 * `language_code`. Nothing has to translate between them.
 */
enum Locale: string
{
    case Fr = 'fr';
    case En = 'en';

    public const Locale DEFAULT = self::Fr;

    /**
     * Fold anything request-shaped onto a supported locale.
     *
     * Total by design: an unsupported language (`de-DE`), a region-qualified tag (`en-GB`) or an
     * empty string must all resolve rather than throw. A voice endpoint is garnish — it must never
     * be the thing that 500s a turn.
     */
    public static function resolve(?string $tag): self
    {
        $primary = strtolower(explode('-', (string) $tag)[0]);

        return self::tryFrom($primary) ?? self::DEFAULT;
    }
}
