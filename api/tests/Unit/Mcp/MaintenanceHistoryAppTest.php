<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\MaintenanceHistoryApp;
use PHPUnit\Framework\TestCase;

final class MaintenanceHistoryAppTest extends TestCase
{
    public function testServesTheFragmentWithTheTokensInlined(): void
    {
        $contents = (new MaintenanceHistoryApp(\dirname(__DIR__, 3).'/resources/mcp-ui', \dirname(__DIR__, 3).'/public/design/tokens'))();

        $this->assertSame(MaintenanceHistoryApp::URI, $contents->uri);
        $this->assertSame('text/html;profile=mcp-app', $contents->mimeType);
        $this->assertStringContainsString('data-mcp-app="maintenance-history"', $contents->text);
        $this->assertStringContainsString('--mpa-', $contents->text);
        $this->assertTrue($contents->meta['ui']['prefersBorder'] ?? null);
    }

    public function testAMissingFragmentNamesTheFileInsteadOfServingAnEmptyApp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('/nonexistent/mcp-ui/maintenance-history.html');

        (new MaintenanceHistoryApp('/nonexistent/mcp-ui', \dirname(__DIR__, 3).'/public/design/tokens'))();
    }

    public function testAMissingTokenFileNamesTheFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('/nonexistent/tokens/colors.css');

        (new MaintenanceHistoryApp(\dirname(__DIR__, 3).'/resources/mcp-ui', '/nonexistent/tokens'))();
    }
}
