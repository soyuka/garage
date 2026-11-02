<?php

declare(strict_types=1);

namespace App\Agent\Finder;

/**
 * The plain size limits on everything a caller hands to a model call (finder turn, photo intake,
 * carte grise reader). PHP accepts 50M per request: these are the ceilings that matter on a public
 * demo, mirrored by the front (`bonneref/src/limits.ts`).
 */
final class TurnInputLimits
{
    public const int MAX_INSTRUCTION_LENGTH = 500;

    public const int MAX_IMAGES = 4;

    public const int MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const string SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
}
