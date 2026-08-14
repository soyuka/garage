<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class NarrateStepInput
{
    #[ApiProperty(description: 'The running step sentence, in French, first person present: "Je relis le carnet…", "Je cherche la référence…".')]
    public string $message = '';
}
