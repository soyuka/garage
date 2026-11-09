<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class NarrateStepInput
{
    #[ApiProperty(description: 'The running step sentence, in the interface language, first person present: "Je relis le carnet…" / "Rereading the logbook…".')]
    #[Assert\Length(max: 2000)]
    public string $message = '';
}
