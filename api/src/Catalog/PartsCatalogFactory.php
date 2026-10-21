<?php

declare(strict_types=1);

namespace App\Catalog;

use Psr\Container\ContainerInterface;

final class PartsCatalogFactory
{
    public static function create(CatalogSource $source, ContainerInterface $catalogs): PartsCatalog
    {
        return $catalogs->get($source->value);
    }
}
