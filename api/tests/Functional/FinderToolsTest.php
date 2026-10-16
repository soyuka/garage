<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderRenderTools;
use App\Agent\Finder\FinderVehicle;
use Symfony\AI\Platform\Tool\Tool;

final class FinderToolsTest extends AppTestCase
{
    public function testSetVehicleHandsBackTheLeafCategoriesOfThatCar(): void
    {
        static::bootKernel();

        $result = static::getContainer()->get(FinderRenderTools::class)->setVehicle(16182, 'PC', 'PEUGEOT', '307', '1.6 HDi');

        self::assertStringContainsString("[Direction]\n", $result);
        self::assertStringContainsString("\n100198 Rotules", $result);
        self::assertStringNotContainsString('706383', $result);
    }

    public function testTheFinderAgentHasNoKeywordCategorySearch(): void
    {
        static::bootKernel();

        $names = array_map(static fn (Tool $t): string => $t->getName(), static::getContainer()->get('ai.toolbox.finder')->getTools());

        self::assertContains('show_parts', $names);
        self::assertContains('lookup_plate', $names);
        self::assertNotContains('list_part_categories', $names);
    }

    public function testShowPartsTellsTheAgentWhatMeasureSettlesTheChoice(): void
    {
        static::bootKernel();
        $tools = static::getContainer()->get(FinderRenderTools::class);
        $tools->bindVehicle(new FinderVehicle(16182, make: 'PEUGEOT', model: '307'));

        $summary = $tools->showParts(100198, 'Rotule axiale.', 51);

        self::assertSame('shown', $summary['status']);
        self::assertSame(['Longeur rotule [mm] : c1 322–338 mm, c2 358 mm'], $summary['differsOn']);
    }
}
