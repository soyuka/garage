<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\UiResourceLoader;
use Mcp\Capability\Registry;
use PHPUnit\Framework\TestCase;

final class UiResourceLoaderTest extends TestCase
{
    public function testAMissingFragmentNamesTheFileInsteadOfServingAnEmptyApp(): void
    {
        $registry = new Registry();
        (new UiResourceLoader('/nonexistent/mcp-ui', \dirname(__DIR__, 3).'/public/design/tokens'))->load($registry);
        $handler = $registry->getResource(UiResourceLoader::MAINTENANCE_HISTORY_URI)->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/nonexistent/mcp-ui/maintenance-history.html');

        $handler();
    }

    public function testAMissingTokenFileNamesTheFile(): void
    {
        $registry = new Registry();
        (new UiResourceLoader(\dirname(__DIR__, 3).'/resources/mcp-ui', '/nonexistent/tokens'))->load($registry);
        $handler = $registry->getResource(UiResourceLoader::MAINTENANCE_HISTORY_URI)->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/nonexistent/tokens/colors.css');

        $handler();
    }
}
