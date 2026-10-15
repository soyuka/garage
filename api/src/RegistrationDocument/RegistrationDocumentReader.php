<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

interface RegistrationDocumentReader
{
    /**
     * @return array<string, mixed> the model's raw answer; untrusted, whitelisted by {@see RegistrationDocumentExtractor}
     *
     * @throws RegistrationDocumentUnreadable
     */
    public function read(string $content, string $mimeType): array;
}
