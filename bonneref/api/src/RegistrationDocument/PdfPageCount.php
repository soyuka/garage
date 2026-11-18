<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * The page count of an uploaded PDF, read by poppler's `pdfinfo` so the vision call is never billed for a longer
 * document than the cap allows. Anything pdfinfo cannot read in time, or a document without pages, is null and the
 * caller refuses the file.
 */
final class PdfPageCount
{
    private const float TIMEOUT_SECONDS = 5.0;

    public static function of(string $pdf): ?int
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-pages-');
        if (false === $path) {
            return null;
        }

        try {
            if (false === file_put_contents($path, $pdf)) {
                return null;
            }

            $process = new Process(['pdfinfo', $path]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();
            if (!$process->isSuccessful()) {
                return null;
            }

            // The Info strings (Title, Author…) are printed raw before the real line and may forge their own.
            preg_match_all('/^Pages:\s+(\d+)$/m', $process->getOutput(), $matches);
            $pages = [] === $matches[1] ? 0 : max(array_map(intval(...), $matches[1]));

            return $pages > 0 ? $pages : null;
        } catch (ExceptionInterface) {
            return null;
        } finally {
            unlink($path);
        }
    }
}
