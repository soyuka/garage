<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\PhotoIntake\PhotoIntakeReader;

final class FakePhotoIntakeReader implements PhotoIntakeReader
{
    /** @var array<string, mixed> */
    public array $response = ['kind' => 'other', 'legible' => false, 'warnings' => []];

    public int $calls = 0;

    public ?string $lastContent = null;

    public function read(string $content, string $mimeType): array
    {
        ++$this->calls;
        $this->lastContent = $content;

        return $this->response;
    }
}
