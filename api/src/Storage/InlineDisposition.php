<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class InlineDisposition
{
    public static function for(string $filename): string
    {
        // makeDisposition() refuses "/" and "\" in either form, and "%" or non-ASCII in the fallback.
        $filename = str_replace(['/', '\\'], '_', $filename);
        $fallback = preg_replace('/[^\x20-\x7e]|[%"\\\\]/', '_', $filename) ?? '';

        return HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename, '' === $fallback ? 'file' : $fallback);
    }
}
