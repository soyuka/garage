<?php

declare(strict_types=1);

namespace App\Catalog;

enum CatalogSource: string
{
    case PartsApi = 'partsapi';
    case Tecdoc = 'tecdoc';
}
