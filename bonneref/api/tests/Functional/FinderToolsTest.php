<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Exception\RepeatedToolFailureException;
use App\Agent\Finder\FinderRenderTools;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Ui\DashboardChannel;
use App\Agent\Ui\RepeatedToolFailureGuard;
use App\Mcp\ToolError;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

final class FinderToolsTest extends AppTestCase
{
    public function testSetVehicleHandsBackTheLeafCategoriesOfThatCar(): void
    {
        static::bootKernel();
        static::getContainer()->get(DashboardChannel::class)->bindSession('https://localhost/agent/sessions/sess-tools');

        $result = static::getContainer()->get(FinderRenderTools::class)->setVehicle(19107, 'PC', 'MAZDA', '6', '2.0');

        $this->assertIsString($result);
        $this->assertStringContainsString("[Direction]\n", $result);
        $this->assertStringContainsString("\n100198 Rotules", $result);
        $this->assertStringContainsString("[Filtre]\n100259 Filtre à huile", $result);
    }

    public function testTheFinderAgentHasNoKeywordCategorySearch(): void
    {
        static::bootKernel();

        $toolbox = static::getContainer()->get('ai.toolbox.finder');
        $this->assertInstanceOf(ToolboxInterface::class, $toolbox);
        $names = array_map(static fn (Tool $t): string => $t->getName(), $toolbox->getTools());

        $this->assertContains('show_parts', $names);
        $this->assertContains('lookup_plate', $names);
        $this->assertNotContains('list_part_categories', $names);
    }

    public function testShowPartsTellsTheAgentWhatMeasureSettlesTheChoice(): void
    {
        static::bootKernel();
        static::getContainer()->get(DashboardChannel::class)->bindSession('https://localhost/agent/sessions/sess-tools');
        $tools = static::getContainer()->get(FinderRenderTools::class);
        $tools->bindVehicle(new FinderVehicle(19107, make: 'MAZDA', model: '6'));

        $summary = $tools->showParts(100198, 'Rotule axiale.', 51);

        $this->assertIsArray($summary);
        $this->assertSame('shown', $summary['status']);
        $this->assertSame(['Diamètre extérieur [mm] : c1 20 mm, c2 30 mm'], $summary['differsOn']);
    }

    public function testEveryRefusalIsAToolError(): void
    {
        static::bootKernel();
        static::getContainer()->get(DashboardChannel::class)->bindSession('https://localhost/agent/sessions/sess-tools');
        $tools = static::getContainer()->get(FinderRenderTools::class);

        $tools->bindVehicle(null);
        self::assertToolError('refused', $tools->showParts(100198, 'x'));
        self::assertToolError('refused', $tools->setBuildCode('982488'));
        self::assertToolError('refused', $tools->setVehicle(0, 'PC', 'MAZDA', '6'));
        self::assertToolError('refused', $tools->showWebPart('Courroie', 'CT1088', 'CONTITECH', 'https://example.com/a', 'A', 'x'));

        $tools->bindVehicle(new FinderVehicle(19107, make: 'PEUGEOT', model: '206'));
        self::assertToolError('invalid_input', $tools->setBuildCode('not a code'));
    }

    public function testTheLoopGuardCountsARepeatedRefusal(): void
    {
        static::bootKernel();
        $tools = static::getContainer()->get(FinderRenderTools::class);
        $tools->bindVehicle(null);
        $guard = new RepeatedToolFailureGuard(maxRepeats: 2);
        $call = new ToolCall('1', 'show_parts', ['categoryId' => 100198, 'rationale' => 'x']);

        $guard(new ToolCallsExecuted([new ToolResult($call, $tools->showParts(100198, 'x'))]));

        $this->expectException(RepeatedToolFailureException::class);
        $guard(new ToolCallsExecuted([new ToolResult($call, $tools->showParts(100198, 'x'))]));
    }

    private static function assertToolError(string $status, mixed $result): void
    {
        self::assertInstanceOf(ToolError::class, $result);
        self::assertSame($status, $result->status);
        self::assertTrue($result->isError);
    }
}
