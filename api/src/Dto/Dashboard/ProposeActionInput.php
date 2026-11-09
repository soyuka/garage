<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ProposeActionInput
{
    #[ApiProperty(description: 'The button label, in the interface language.')]
    #[Assert\Length(max: 200)]
    public string $label = '';

    #[ApiProperty(description: 'NL follow-up: clicking re-runs the IN-APP agent with this text. Leave null for a deterministic write.')]
    #[Assert\Length(max: 2000)]
    public ?string $instruction = null;

    #[ApiProperty(description: 'Deterministic write: the open task id (from list_planned_maintenance) the parts are merged onto by a plain PATCH — no agent turn.')]
    #[Assert\Length(max: 200)]
    public ?string $taskId = null;

    /**
     * Nullable because models fill the unused kind with an explicit null, and API Platform 5.0.6
     * validates tool arguments against this schema before the processor runs.
     *
     * @var list<ProposePartInput>|null
     */
    #[ApiProperty(description: 'Deterministic write: the parts merged onto taskId when the button is clicked. Leave null for an NL follow-up.')]
    #[Assert\Count(max: 50)]
    public ?array $parts = null;
}
