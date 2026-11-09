<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class FocusViewInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['tasks', 'specs', 'carnet', 'parts', 'docs'], 'description' => '"tasks" = the maintenance to-do list, "specs" = the spec/échéance panel, "carnet" = the past visits + spend projection, "parts" = the shopping list of every reference already chosen across the open checklist, "docs" = the LIST of the vehicle\'s documents (manuels d\'atelier, notes techniques, devis et factures); to open ONE document at a page use show_document.'])]
    public string $panel = 'tasks';

    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service-type or fact keys to spotlight (e.g. ["oil_change"]); each lights its body region on the car. Omit to just raise the panel.'])]
    #[Assert\Count(max: 50)]
    public array $keys = [];
}
