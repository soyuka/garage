<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PdfFixture
{
    /**
     * @param list<list<string>> $pages lines of text per page
     */
    public static function build(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        $next = 4;
        foreach ($pages as $lines) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId.' 0 R';

            $stream = "BT\n/F1 12 Tf\n14 TL\n50 750 Td\n";
            foreach ($lines as $line) {
                $stream .= '('.strtr(mb_convert_encoding($line, 'Windows-1252', 'UTF-8'), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).") Tj T*\n";
            }
            $stream .= 'ET';

            $objects[$pageId] = \sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents %d 0 R /Resources << /Font << /F1 3 0 R >> >> >>', $contentId);
            $objects[$contentId] = \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream);
        }
        $objects[2] = \sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), \count($pages));
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = \strlen($out);
            $out .= \sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }
        $xref = \strlen($out);
        $out .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $out .= \sprintf("%010d 00000 n \n", $offset);
        }
        $out .= \sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);

        return $out;
    }

    public static function renaultManual(): string
    {
        return self::build([
            ['MR 361 Transmission', 'Remplacement du joint de sortie de boite', 'Couple de serrage du bouchon de vidange 45 Nm', '21A-5'],
            ['Boite de vitesses JH3', 'Capacité en huile 2,4 litres - préconisation Elf Tranself', '21A-6'],
        ]);
    }

    public static function rankingManual(): string
    {
        return self::build([
            ['Bruit de direction', 'Vis de fixation du boitier, un couple de forces sur le volant', 'Regle de la de du de des de la de du de', 'Le volant de la colonne de direction'],
            ['Colonne de direction', 'Le volant de direction est reglable en hauteur'],
            ['Embrayage', 'Vis de volant moteur', 'COUPLE DE SERRAGE 70 Nm'],
        ]);
    }
}
