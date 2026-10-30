<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\EndTurnTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;

final class EndTurnToolTest extends TestCase
{
    public function testAcks(): void
    {
        $this->assertSame('ok', (new EndTurnTool())->endTurn());
    }

    public function testTakesNoArguments(): void
    {
        // A bare, argument-free signal: the model just has to call it, nothing to fill in.
        $schema = (new Factory())->buildParameters(EndTurnTool::class, 'endTurn');

        $this->assertSame([], $schema['properties'] ?? []);
    }
}
