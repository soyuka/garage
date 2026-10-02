<?php

declare(strict_types=1);

namespace App\Documentation;

interface PdfTextExtractor
{
    /**
     * @return list<array{number: int, label: ?string, text: string}>
     *
     * @throws InvalidVehicleDocumentException when the file cannot be read as a PDF
     */
    public function extract(string $path): array;
}
