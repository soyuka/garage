<?php

declare(strict_types=1);

namespace App\Documentation;

enum VehicleDocumentKind: string
{
    case Manual = 'manual';
    case TechnicalNote = 'technical_note';
    case Quote = 'quote';
    case Other = 'other';
}
