<?php

declare(strict_types=1);

namespace App\Documentation;

final readonly class DocumentMeta
{
    public function __construct(
        public string $id,
        public string $title,
        public string $filename,
        public string $contentType,
        public int $size,
    ) {
    }
}
