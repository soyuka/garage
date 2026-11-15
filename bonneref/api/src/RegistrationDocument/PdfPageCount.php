<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

/**
 * A conservative page count of an uploaded PDF, so the vision call is never billed for a long document. No PDF
 * library ships in the image, and the count only has to err high: it is the larger of the page objects found
 * (in the file and in its Flate object streams, where PDF 1.5+ hides them) and the largest page-tree `/Count`.
 * Whatever cannot be read (an object stream in another filter, a stream that does not inflate, no page at
 * all) is null, and the caller refuses the file.
 */
final class PdfPageCount
{
    private const int MAX_INFLATED_BYTES = 32 * 1024 * 1024;
    private const int DICTIONARY_WINDOW = 4096;

    public static function of(string $pdf): ?int
    {
        $texts = [self::unescapeNames($pdf)];
        $inflated = 0;

        for ($offset = strpos($pdf, 'stream'); false !== $offset; $offset = strpos($pdf, 'stream', $offset + 6)) {
            $start = $offset + 6;
            if ("\r" === ($pdf[$start] ?? '')) {
                ++$start;
            }
            if (str_ends_with(substr($pdf, max(0, $offset - 3), 3), 'end') || "\n" !== ($pdf[$start] ?? '')) {
                continue;
            }
            ++$start;

            $dictionary = self::unescapeNames(substr($pdf, max(0, $offset - self::DICTIONARY_WINDOW), min($offset, self::DICTIONARY_WINDOW)));
            $dictionary = substr($dictionary, (int) strrpos($dictionary, 'obj'));
            if (1 !== preg_match('~/Type\s*/ObjStm(?![A-Za-z0-9])~', $dictionary)) {
                continue;
            }

            $end = strpos($pdf, 'endstream', $start);
            if (false === $end) {
                return null;
            }
            $data = substr($pdf, $start, $end - $start);
            $length = [];
            if (1 === preg_match('~/Length\s+(\d+)(?!\s+\d+\s+R)~', $dictionary, $length)) {
                $data = substr($data, 0, (int) $length[1]);
            }

            $decoded = self::decode($dictionary, $data, self::MAX_INFLATED_BYTES - $inflated);
            if (null === $decoded) {
                return null;
            }
            $inflated += \strlen($decoded);
            $texts[] = self::unescapeNames($decoded);
        }

        $pages = 0;
        $declared = 0;
        foreach ($texts as $text) {
            $pages += (int) preg_match_all('~/Type\s*/Page(?![A-Za-z0-9])~', $text);
            $counts = [];
            preg_match_all('~/Count\s+(\d+)~', $text, $counts);
            foreach ($counts[1] ?? [] as $count) {
                $declared = max($declared, (int) $count);
            }
        }

        $count = max($pages, $declared);

        return $count > 0 ? $count : null;
    }

    private static function decode(string $dictionary, string $data, int $budget): ?string
    {
        if (!str_contains($dictionary, '/Filter')) {
            return $data;
        }

        // Exactly one FlateDecode and no predictor: any other filter chain is refused rather than half-read.
        if (1 !== preg_match('~/Filter\s*(?:\[\s*)?/FlateDecode\s*(?:\]|/(?!Filter)|>>|\s)~', $dictionary)
            || (int) preg_match_all('~/[A-Za-z0-9]+Decode(?![A-Za-z0-9])~', $dictionary) > 1
            || str_contains($dictionary, '/DecodeParms')
            || $budget <= 0) {
            return null;
        }

        // A corrupt or oversized stream is a warning, not an exception: it only means "refuse the file".
        set_error_handler(static fn (): bool => true);
        try {
            $inflated = gzuncompress($data, $budget);
        } finally {
            restore_error_handler();
        }

        return false === $inflated ? null : $inflated;
    }

    /** `/P#61ge` is the name `/Page`: PDF names may hex-escape any character. */
    private static function unescapeNames(string $text): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => \chr((int) hexdec($m[1])), $text);
    }
}
