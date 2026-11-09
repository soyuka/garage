<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Ui\RenderIntentTools;
use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;

final class RenderIntentToolSchemaTest extends AppTestCase
{
    public function testSpokenArgumentsAdvertiseTheirLengthCap(): void
    {
        static::createClient();
        $factory = self::serviceAs('ai.tool_factory', ToolFactoryInterface::class);

        $schemas = [];
        foreach ($factory->getTool(RenderIntentTools::class) as $tool) {
            $schemas[$tool->getName()] = $tool->getParameters();
        }

        $this->assertSame(200, $schemas['propose_actions']['properties']['finding']['maxLength'] ?? null);
        $this->assertSame(140, $schemas['toast']['properties']['message']['maxLength'] ?? null);
        $this->assertSame(100, $schemas['narrate_step']['properties']['message']['maxLength'] ?? null);
    }
}
