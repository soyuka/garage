<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /garage/vehicles/{vehicleId}/planned-tasks — a checklist item the user adds by
 * hand. Mirrors plan_maintenance's writable surface, minus the vehicle-resolution arguments (the
 * vehicle is the URI parent). Lands as source "user".
 */
final class PlannedTaskInput
{
    #[Assert\NotBlank]
    #[ApiProperty(description: 'Free-form service label (snake_case), e.g. "oil_change", "brakes", "timing_belt".')]
    #[Assert\Length(max: 100)]
    public string $type = '';

    #[ApiProperty(description: 'Optional human label distinguishing two tasks of the same type, e.g. "plaquettes avant".')]
    #[Assert\Length(max: 200)]
    public ?string $label = null;

    /** @var array{value: int, unit?: string|null}|null */
    #[ApiProperty(description: 'Usage reading the work is pegged to, e.g. {"value":160000,"unit":"km"}.')]
    public ?array $target = null;

    #[ApiProperty(description: 'Due date (YYYY-MM-DD) the work is pegged to, when there is no usage target.')]
    #[Assert\Date]
    public ?string $dueDate = null;

    /**
     * @var list<array{ref: string, label?: string, brand?: string, qty?: int}>
     */
    #[ApiProperty(description: 'Parts/fluids the task needs — the shopping list.')]
    #[Assert\Count(max: 50)]
    public array $parts = [];
}
