<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class RemovePlannedPartInput
{
    #[ApiProperty(required: true, description: 'The planned task\'s @id (IRI), e.g. "/garage/planned-tasks/01J…", as returned by list_planned_maintenance or plan_maintenance.')]
    public string $task;

    #[ApiProperty(required: true, description: 'The shopping-list line to remove: its `id` (ULID) as returned in the task\'s `parts[].id`.')]
    public string $part;
}
