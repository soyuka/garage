<?php

declare(strict_types=1);

namespace App\Documentation;

enum VehicleDocumentSource: string
{
    case Dialogys = 'dialogys';
    case Upload = 'upload';
}
