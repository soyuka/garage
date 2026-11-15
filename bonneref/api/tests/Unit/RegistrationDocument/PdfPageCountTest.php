<?php

declare(strict_types=1);

namespace App\Tests\Unit\RegistrationDocument;

use App\RegistrationDocument\PdfPageCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfPageCountTest extends TestCase
{
    public function testCountsThePageObjects(): void
    {
        $this->assertSame(1, PdfPageCount::of(self::pdf(1)));
        $this->assertSame(3, PdfPageCount::of(self::pdf(3)));
    }

    public function testTrustsTheLargestPageTreeCount(): void
    {
        $this->assertSame(40, PdfPageCount::of("%PDF-1.4\n1 0 obj<</Type/Pages/Count 40/Kids[2 0 R]>>endobj\n2 0 obj<</Type/Page/Parent 1 0 R>>endobj\n%%EOF"));
    }

    public function testFindsPagesInsideACompressedObjectStream(): void
    {
        $objects = str_repeat('<</Type /Page /Parent 1 0 R>> ', 5);
        $stream = (string) gzcompress($objects);
        $pdf = "%PDF-1.5\n1 0 obj<</Type/Pages/Kids[]>>endobj\n9 0 obj<</Type/ObjStm/N 5/First 0/Filter/FlateDecode/Length ".\strlen($stream).">>stream\n".$stream."\nendstream\nendobj\n%%EOF";

        $this->assertSame(5, PdfPageCount::of($pdf));
    }

    public function testReadsHexEscapedNames(): void
    {
        $this->assertSame(3, PdfPageCount::of("%PDF-1.4\n".str_repeat("<</Type/P#61ge>>\n", 3).'%%EOF'));
    }

    #[DataProvider('undeterminable')]
    public function testFailsClosedWhenThePagesCannotBeCounted(string $pdf): void
    {
        $this->assertNull(PdfPageCount::of($pdf));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undeterminable(): iterable
    {
        yield 'no page at all' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"];
        yield 'object stream in an unsupported filter' => ["%PDF-1.5\n9 0 obj<</Type/ObjStm/Filter/LZWDecode/Length 3>>stream\nabc\nendstream\nendobj\n<</Type/Page>>%%EOF"];
        yield 'object stream that does not inflate' => ["%PDF-1.5\n9 0 obj<</Type/ObjStm/Filter/FlateDecode/Length 3>>stream\nabc\nendstream\nendobj\n<</Type/Page>>%%EOF"];
    }

    private static function pdf(int $pages): string
    {
        $kids = implode(' ', array_map(static fn (int $i): string => ($i + 2).' 0 R', range(0, $pages - 1)));
        $body = "%PDF-1.4\n1 0 obj<</Type /Pages /Count {$pages} /Kids [{$kids}]>>endobj\n";
        for ($i = 0; $i < $pages; ++$i) {
            $body .= ($i + 2)." 0 obj<</Type /Page /Parent 1 0 R /MediaBox [0 0 595 842]>>endobj\n";
        }

        return $body.'%%EOF';
    }
}
