<?php

declare(strict_types=1);

namespace App\PhotoIntake;

enum PhotoKind: string
{
    case CarteGrise = 'carte_grise';
    case Part = 'part';
    case Other = 'other';
}
