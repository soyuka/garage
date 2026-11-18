<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\PopplerPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class PopplerPdfTextExtractorTest extends TestCase
{
    public function testAnUnreadablePdfIsRefusedWithAFixedMessageAndTheDetailIsLogged(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mpa_pdf_');
        file_put_contents($path, "%PDF-1.4\ngarbage\n");
        $logger = new BufferingLogger();

        try {
            new PopplerPdfTextExtractor($logger)->extract($path);
            $this->fail('an unreadable PDF must be refused');
        } catch (InvalidVehicleDocumentException $e) {
            $this->assertSame('The PDF could not be read.', $e->getMessage());
        } finally {
            unlink($path);
        }

        $logs = $logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Syntax Error', (string) ($logs[0][2]['stderr'] ?? ''));
    }
}
