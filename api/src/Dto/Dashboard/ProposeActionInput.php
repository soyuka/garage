<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ProposeActionInput
{
    #[ApiProperty(description: 'The button label, in the interface language.')]
    public string $label = '';

    #[ApiProperty(description: 'NL follow-up: clicking re-runs the IN-APP agent with this text. Leave null for a deterministic write.')]
    public ?string $instruction = null;

    #[ApiProperty(description: 'Deterministic write: the open task id (from list_planned_maintenance) the parts are merged onto by a plain PATCH — no agent turn.')]
    public ?string $taskId = null;

    /**
     * @var list<ProposePartInput>
     */
    #[ApiProperty(description: 'Deterministic write: the parts merged onto taskId when the button is clicked.')]
    public array $parts = [];
}
