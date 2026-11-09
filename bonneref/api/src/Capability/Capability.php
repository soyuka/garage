<?php

declare(strict_types=1);

namespace App\Capability;

/**
 * An optional, paid provider-backed feature. An McpTool needing one declares
 * `extraProperties: ['requires' => Capability::X]`; while {@see Capabilities} says it is not
 * configured the tool is not advertised ({@see CapabilityGatedResourceMetadataCollectionFactory}).
 */
enum Capability: string
{
    /** `lookup_plate`: the SIV registry (apiplaqueimmatriculation.com, API_PLAQUE_TOKEN). */
    case PlateLookup = 'plate_lookup';
}
