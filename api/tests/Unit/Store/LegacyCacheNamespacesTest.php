<?php

declare(strict_types=1);

namespace App\Tests\Unit\Store;

use App\Command\LegacyCacheNamespaces;
use PHPUnit\Framework\TestCase;

final class LegacyCacheNamespacesTest extends TestCase
{
    public function testDerivesTheNamespacesTheDevPoolsWroteLive(): void
    {
        // Measured in the live cache_items table on 2026-10-16 (APP_ENV=dev, project dir /app).
        $namespaces = new LegacyCacheNamespaces('_/app.App_KernelDevDebugContainer');

        $this->assertSame('5-RH+qA0Ld', $namespaces->of('cache.siv'));
        $this->assertSame('flXcHuby96', $namespaces->of('cache.partsapi'));
        $this->assertSame('CGsR3jBwRX', $namespaces->of('cache.motul'));
    }
}
