<?php

declare(strict_types=1);

namespace App\Validator;

/**
 * A stored source link is rendered as an `href`, and FILTER_VALIDATE_URL alone accepts any scheme
 * (`javascript://x%0Aalert(1)` included): only absolute http(s) URLs pass.
 */
final class WebUrl
{
    public const array PROTOCOLS = ['http', 'https'];

    public static function isValid(string $url): bool
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(strtolower($scheme), self::PROTOCOLS, true);
    }
}
