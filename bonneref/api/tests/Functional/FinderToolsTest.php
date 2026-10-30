<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderRenderTools;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Ui\DashboardChannel;
use Symfony\AI\Platform\Tool\Tool;

final class FinderToolsTest extends AppTestCase
{
    public function testSetVehicleHandsBackTheLeafCategoriesOfThatCar(): void
    {
        static::bootKernel();
        static::getContainer()->get(DashboardChannel::class)->bindSession('https://localhost/agent/sessions/sess-tools');

        $result = static::getContainer()->get(FinderRenderTools::class)->setVehicle(19107, 'PC', 'MAZDA', '6', '2.0');

        self::assertStringContainsString("[Direction]\n", $result);
        self::assertStringContainsString("\n100198 Rotules", $result);
        self::assertStringContainsString("[Filtre]\n100259 Filtre à huile", $result);
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
        static::getContainer()->get(DashboardChannel::class)->bindSession('https://localhost/agent/sessions/sess-tools');
        $tools = static::getContainer()->get(FinderRenderTools::class);
        $tools->bindVehicle(new FinderVehicle(19107, make: 'MAZDA', model: '6'));

        $summary = $tools->showParts(100198, 'Rotule axiale.', 51);

        self::assertSame('shown', $summary['status']);
        self::assertSame(['Diamètre extérieur [mm] : c1 20 mm, c2 30 mm'], $summary['differsOn']);
    }
}
