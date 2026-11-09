<?php

declare(strict_types=1);

namespace App\Capability;

/**
 * On an `#[AsTool]` class: the agent toolbox only offers the tool while the capability is configured
 * ({@see \App\Agent\Toolbox\CompositeToolbox}). The MCP-side twin is `extraProperties: ['requires' =>
 * Capability::X]` on an McpTool ({@see CapabilityGatedResourceMetadataCollectionFactory}).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RequiresCapability
{
    public function __construct(public Capability $capability)
    {
    }
}
