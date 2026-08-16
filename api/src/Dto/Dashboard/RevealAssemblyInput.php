<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class RevealAssemblyInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['hood', 'bay', 'windshield', 'frontAxle', 'rearAxle'], 'description' => 'The body region to open up. "hood" = the engine itself (oil, plugs, coolant), "bay" = the engine bay around it (air/fuel filters, battery), "windshield" = the cabin front (wipers, cabin filter), "frontAxle"/"rearAxle" = the brakes and wheels of that axle.'])]
    public string $region = 'hood';

    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service-type keys to CALL OUT inside the diagram (e.g. ["fuel_filter"]) — they are drawn in the operator colour. They never filter the diagram: the whole circuit is always drawn, because "where does this part sit" is answered by what surrounds it. Omit to raise the diagram with nothing called out.'])]
    public array $partKeys = [];
}
