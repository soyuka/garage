<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\RegistrationDocument\RegistrationDocumentReader;

final class FakeRegistrationDocumentReader implements RegistrationDocumentReader
{
    /** @var array<string, mixed> */
    public array $response = [
        'A' => 'AB-123-CD',
        'B' => '2004-09-15',
        'D.1' => 'PEUGEOT',
        'E' => 'VF33CKFWB83471925',
        'legible' => true,
        'warnings' => [],
    ];

    public int $calls = 0;

    public ?string $lastContent = null;

    public ?string $lastMimeType = null;

    public function read(string $content, string $mimeType): array
    {
        ++$this->calls;
        $this->lastContent = $content;
        $this->lastMimeType = $mimeType;

        return $this->response;
    }
}
