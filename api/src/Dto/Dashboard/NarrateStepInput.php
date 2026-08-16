<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class NarrateStepInput
{
    #[ApiProperty(description: 'The running step sentence, in the interface language, first person present: "Je relis le carnet…" / "Rereading the logbook…".')]
    public string $message = '';
}
