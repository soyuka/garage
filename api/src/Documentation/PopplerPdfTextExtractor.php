<?php

declare(strict_types=1);

namespace App\Documentation;

use Symfony\Component\Process\Process;

final class PopplerPdfTextExtractor implements PdfTextExtractor
{
    private const string RENAULT_PAGE_LABEL = '/\b(\d{2}[A-Z])-(\d{1,3})\b/';

    public function extract(string $path): array
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new InvalidVehicleDocumentException(\sprintf('The PDF could not be read: %s', trim($process->getErrorOutput())));
        }

        $chunks = explode("\f", $process->getOutput());
        if ('' === end($chunks)) {
            array_pop($chunks);
        }

        $pages = [];
        foreach ($chunks as $index => $text) {
            $pages[] = ['number' => $index + 1, 'label' => $this->label($text), 'text' => $text];
        }

        return $pages;
    }

    private function label(string $text): ?string
    {
        if (0 === preg_match_all(self::RENAULT_PAGE_LABEL, $text, $matches)) {
            return null;
        }

        $last = end($matches[0]);

        return false === $last ? null : $last;
    }
}
