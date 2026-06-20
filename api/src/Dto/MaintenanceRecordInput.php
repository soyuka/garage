<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /garage/vehicles/{vehicleId}/maintenance-records — a past service the user logs by
 * hand. Lands as source "user". Cost is entered in major units (euros) and stored as integer cents.
 */
final class MaintenanceRecordInput
{
    #[Assert\NotBlank]
    #[ApiProperty(description: 'Free-form service label (snake_case), e.g. "oil_change", "brakes".')]
    public string $type = '';

    #[Assert\NotBlank]
    #[ApiProperty(description: 'Date the service was performed (YYYY-MM-DD).')]
    public string $performedAt = '';

    #[ApiProperty(description: 'Optional human label, e.g. "vidange annuelle".')]
    public ?string $label = null;

    #[ApiProperty(description: 'Odometer/engine-hours reading at service time.')]
    public ?int $usageValue = null;

    #[ApiProperty(description: 'Unit of the reading: "km", "mi" or "h" (engine hours).')]
    public ?string $usageUnit = null;

    #[ApiProperty(description: 'Free-text notes about the work.')]
    public ?string $notes = null;

    #[ApiProperty(description: 'Cost in major units (e.g. 89.90 for €89.90).')]
    public ?float $cost = null;

    #[ApiProperty(description: 'ISO currency of the cost, e.g. "EUR". Defaults to EUR when a cost is given.')]
    public ?string $currency = null;

    #[ApiProperty(description: 'Who performed the work (garage name, "moi-même"…).')]
    public ?string $performedBy = null;

    /**
     * @var list<array{ref: string, label?: string}>
     */
    #[ApiProperty(description: 'Parts/fluids used during the service.')]
    public array $partsUsed = [];
}
