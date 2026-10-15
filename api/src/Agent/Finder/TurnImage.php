<?php

declare(strict_types=1);

namespace App\Agent\Finder;

final readonly class TurnImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {
    }

    /**
     * @return array{mimeType: string, bytes: int}
     */
    public function shape(): array
    {
        return ['mimeType' => $this->mimeType, 'bytes' => \strlen($this->bytes)];
    }
}
