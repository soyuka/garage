<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ProposeActionsInput
{
    #[ApiProperty(description: 'The card headline, in French.')]
    public string $headline = '';

    /**
     * @var list<ProposeActionInput>
     */
    #[ApiProperty(description: 'The next-step buttons. Each is EITHER an NL follow-up (instruction) OR a deterministic write (taskId + parts).')]
    public array $actions = [];

    #[ApiProperty(description: 'Optional one-line finding under the headline, e.g. "2 références compatibles trouvées au catalogue".')]
    public ?string $finding = null;
}
