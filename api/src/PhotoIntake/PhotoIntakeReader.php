<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\RegistrationDocument\RegistrationDocumentUnreadable;

interface PhotoIntakeReader
{
    /**
     * @return array<string, mixed> the model's raw answer; untrusted, whitelisted by {@see PhotoIntake}
     *
     * @throws RegistrationDocumentUnreadable
     */
    public function read(string $content, string $mimeType): array;
}
