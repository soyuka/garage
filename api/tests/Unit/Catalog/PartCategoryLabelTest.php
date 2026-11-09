<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\ApiResource\PartCategory;
use PHPUnit\Framework\TestCase;

final class PartCategoryLabelTest extends TestCase
{
    public function testTheLabelIsTheLeafOfThePathNotTheName(): void
    {
        $this->assertSame('Filtre à huile', (new PartCategory(100259, 100000, 2, 'Oil Filter', 'Filtres > Filtre à huile', true))->label());
    }

    public function testTheLabelFallsBackToTheNameWithoutAPath(): void
    {
        $this->assertSame('Oil Filter', (new PartCategory(1, null, 1, 'Oil Filter', '', true))->label());
    }
}
