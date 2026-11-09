<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Maintenance\VehicleFactWriter;
use Symfony\Component\Validator\Constraints as Assert;

final class SetVehicleFactsInput
{
    #[ApiProperty(required: true, description: 'The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    #[Assert\Length(max: 200)]
    public string $vehicle;

    /**
     * @var list<array{key: string, value: string, unit?: string, source: string, url?: string}>
     */
    #[ApiProperty(required: true, schema: [
        'type' => 'array',
        'minItems' => 1,
        'items' => [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(_[a-z0-9]+)*$', 'description' => 'snake_case, subject first then aspect, e.g. oil_type, oil_capacity, coolant_type, tyre_front_size, paint_code, build_date, engine_code, gearbox_code, fuel_type, distribution_type, oil_filter_ref. Reuse an existing key (see get_vehicle_facts) rather than inventing a synonym: writing an existing key replaces its value.'],
                'value' => ['type' => 'string', 'description' => 'The value as text, e.g. "41W", "205/55 R16", "2007-03".'],
                'unit' => ['type' => 'string', 'description' => 'Unit of a quantity, e.g. "L", "bar", "Nm". Omit for a plain string.'],
                'source' => ['type' => 'string', 'enum' => VehicleFactWriter::WRITABLE_SOURCES, 'description' => '"user" when the owner stated it (it then outranks every other source), "web" with the cited url, "llm" for general knowledge, "motul" for the Motul recommendation. Catalog (partsapi/siv) and bill-derived facts are written by the system, never here.'],
                'url' => ['type' => 'string', 'description' => 'Source page, for a web/motul fact.'],
            ],
            'required' => ['key', 'value', 'source'],
        ],
        'description' => 'The facts to record. Each is {key, value, unit?, source, url?}. A key already on the vehicle is replaced; `verified` is derived from the source, never supplied.',
    ])]
    #[Assert\Count(max: 100)]
    public array $facts;
}
