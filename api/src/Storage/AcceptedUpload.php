<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\Mime\MimeTypes;

/**
 * What the garage accepts as a stored file: PDF, JPEG, PNG, sniffed from the bytes (never the client's
 * header or extension), at most 10 MiB for bills and turn attachments. One definition for every upload
 * path, so a new format is a one-line change.
 */
final class AcceptedUpload
{
    /** 10 MiB: a scanned multi-page invoice fits comfortably; anything larger is likely a mistake. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    public const array TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public static function sniff(string $path): ?string
    {
        $mime = MimeTypes::getDefault()->guessMimeType($path);

        return \in_array($mime, self::TYPES, true) ? $mime : null;
    }
}
