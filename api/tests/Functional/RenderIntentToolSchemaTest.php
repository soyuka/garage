<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Ui\RenderIntentTools;

final class RenderIntentToolSchemaTest extends AppTestCase
{
    public function testSpokenArgumentsAdvertiseTheirLengthCap(): void
    {
        static::createClient();
        $factory = static::getContainer()->get('ai.tool_factory');

        $schemas = [];
        foreach ($factory->getTool(RenderIntentTools::class) as $tool) {
            $schemas[$tool->getName()] = $tool->getParameters();
        }

        self::assertSame(200, $schemas['propose_actions']['properties']['finding']['maxLength'] ?? null);
        self::assertSame(140, $schemas['toast']['properties']['message']['maxLength'] ?? null);
        self::assertSame(100, $schemas['narrate_step']['properties']['message']['maxLength'] ?? null);
    }
}
