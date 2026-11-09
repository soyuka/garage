<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ProposeActionsInput
{
    #[ApiProperty(description: 'The card headline, in the interface language.')]
    #[Assert\Length(max: 200)]
    public string $headline = '';

    /**
     * @var list<ProposeActionInput>
     */
    #[ApiProperty(description: 'The next-step buttons. Each is EITHER an NL follow-up (instruction) OR a deterministic write (taskId + parts).')]
    #[Assert\Count(max: 10)]
    public array $actions = [];

    #[ApiProperty(description: 'Optional one-line finding under the headline, e.g. "2 références compatibles trouvées au catalogue".')]
    #[Assert\Length(max: 2000)]
    public ?string $finding = null;
}
