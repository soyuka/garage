<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Documentation\VehicleDocumentRecorder;
use PHPUnit\Framework\TestCase;

final class PhpUploadLimitsTest extends TestCase
{
    public function testPhpAcceptsADocumentAtTheRecorderLimit(): void
    {
        self::assertGreaterThanOrEqual(VehicleDocumentRecorder::MAX_BYTES, ini_parse_quantity((string) \ini_get('upload_max_filesize')));
        self::assertGreaterThan(VehicleDocumentRecorder::MAX_BYTES, ini_parse_quantity((string) \ini_get('post_max_size')));
    }
}
