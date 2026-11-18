<?php

declare(strict_types=1);

namespace App\Tests\Unit\RegistrationDocument;

use App\RegistrationDocument\PdfPageCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfPageCountTest extends TestCase
{
    private const string CATALOG = '<</Type/Catalog/Pages 2 0 R>>';
    private const string PAGE = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>';

    public function testCountsThePages(): void
    {
        $this->assertSame(1, PdfPageCount::of(self::plainDocument(1)));
        $this->assertSame(3, PdfPageCount::of(self::plainDocument(3)));
    }

    public function testCountsPagesInsideACompressedObjectStream(): void
    {
        $this->assertSame(5, PdfPageCount::of(self::document([1 => self::CATALOG], self::pageTree(5))));
    }

    public function testCountsPagesWhoseTokensAreSplitByComments(): void
    {
        $objects = [1 => self::CATALOG, 2 => '<</Type/Pages/Count%x'."\n".'100/Kids['.self::kids(100).']>>', 3 => self::PAGE];
        for ($id = 4; $id < 103; ++$id) {
            $objects[$id] = '<</Type%x'."\n".'/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>';
        }

        $this->assertSame(100, PdfPageCount::of(self::document($objects)));
    }

    public function testCountsPagesInAnObjectStreamWhoseDictionaryMentionsObj(): void
    {
        $tree = self::pageTree(100);
        $decoy = [1 => self::CATALOG, 3 => self::PAGE];
        unset($tree[3]);

        $this->assertSame(100, PdfPageCount::of(self::document($decoy, $tree, '/Note(obj)')));
    }

    public function testCountsPagesInAnObjectStreamWithAPaddedDictionary(): void
    {
        $tree = self::pageTree(100);
        $decoy = [1 => self::CATALOG, 3 => self::PAGE];
        unset($tree[3]);

        $this->assertSame(100, PdfPageCount::of(self::document($decoy, $tree, '/Pad('.str_repeat('x', 5000).')')));
    }

    public function testIgnoresAPageLineForgedInTheMetadata(): void
    {
        $objects = [1 => self::CATALOG] + self::pageTree(3) + [6 => "<</Title(x\nPages:          1)>>"];

        $this->assertSame(3, PdfPageCount::of(self::document($objects, trailerExtra: '/Info 6 0 R')));
    }

    #[DataProvider('unreadable')]
    public function testFailsClosedWhenThePagesCannotBeCounted(string $pdf): void
    {
        $this->assertNull(PdfPageCount::of($pdf));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'not a PDF' => ['<html>hello</html>'];
        yield 'empty' => [''];
        yield 'no page at all' => [self::document([1 => self::CATALOG, 2 => '<</Type/Pages/Count 0/Kids[]>>'])];
    }

    /**
     * @return array<int, string>
     */
    private static function pageTree(int $pages): array
    {
        $objects = [2 => '<</Type/Pages/Count '.$pages.'/Kids['.self::kids($pages).']>>'];
        for ($i = 0; $i < $pages; ++$i) {
            $objects[$i + 3] = self::PAGE;
        }

        return $objects;
    }

    private static function kids(int $pages): string
    {
        return implode(' ', array_map(static fn (int $i): string => ($i + 3).' 0 R', range(0, $pages - 1)));
    }

    private static function plainDocument(int $pages): string
    {
        return self::document([1 => self::CATALOG] + self::pageTree($pages));
    }

    /**
     * A PDF 1.5 file with a cross-reference stream, so objects can live in a Flate object stream ($packed).
     *
     * @param array<int, string> $objects
     * @param array<int, string> $packed
     */
    private static function document(array $objects, array $packed = [], string $objectStreamExtra = '', string $trailerExtra = ''): string
    {
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $entries = [];
        $next = max(array_keys($objects + $packed)) + 1;

        foreach ($objects as $id => $body) {
            $entries[$id] = [1, \strlen($pdf), 0];
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        if ([] !== $packed) {
            $streamId = $next++;
            $header = '';
            $data = '';
            $index = 0;
            foreach ($packed as $id => $body) {
                $header .= $id.' '.\strlen($data).' ';
                $data .= $body."\n";
                $entries[$id] = [2, $streamId, $index++];
            }
            $compressed = (string) gzcompress($header.$data);
            $entries[$streamId] = [1, \strlen($pdf), 0];
            $pdf .= "{$streamId} 0 obj\n<</Type/ObjStm/N ".\count($packed).'/First '.\strlen($header).'/Filter/FlateDecode/Length '.\strlen($compressed).$objectStreamExtra.">>stream\n{$compressed}\nendstream\nendobj\n";
        }

        $xrefId = $next;
        $entries[$xrefId] = [1, \strlen($pdf), 0];
        $table = pack('CNn', 0, 0, 65535);
        for ($id = 1; $id <= $xrefId; ++$id) {
            [$type, $field, $generation] = $entries[$id] ?? [0, 0, 0];
            $table .= pack('CNn', $type, $field, $generation);
        }
        $offset = $entries[$xrefId][1];
        $pdf .= "{$xrefId} 0 obj\n<</Type/XRef/Size ".($xrefId + 1).'/W[1 4 2]/Root 1 0 R'.$trailerExtra.'/Length '.\strlen($table).">>stream\n{$table}\nendstream\nendobj\n";

        return $pdf."startxref\n{$offset}\n%%EOF\n";
    }
}
