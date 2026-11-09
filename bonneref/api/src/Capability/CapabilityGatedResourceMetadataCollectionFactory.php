<?php

declare(strict_types=1);

namespace App\Capability;

use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;

/**
 * Drops every McpTool declaring `extraProperties: ['requires' => Capability::X]` whose capability is
 * not configured. API Platform's MCP loader (tools/list) and its operation factory (tools/call) both
 * read this collection, so an unconfigured tool is neither advertised nor callable, and the finder
 * agent, which lists the same server ({@see \App\Mcp\Agent\McpToolbox}), never sees it either.
 *
 * Decorates OUTSIDE the cached factory (priority below its -10): the cache holds the full metadata and
 * the filter runs per call, so the env decides, not whatever env the cache was warmed with.
 */
final readonly class CapabilityGatedResourceMetadataCollectionFactory implements ResourceMetadataCollectionFactoryInterface
{
    public const string REQUIRES = 'requires';

    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
        private Capabilities $capabilities,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);
        $filtered = null;

        foreach ($collection as $index => $resource) {
            $mcp = $resource->getMcp();
            if (null === $mcp) {
                continue;
            }

            $kept = array_filter($mcp, fn (object $operation): bool => !$operation instanceof McpTool || $this->available($operation));
            if (\count($kept) === \count($mcp)) {
                continue;
            }

            // A copy: the decorated collection may be the cached factory's in-memory instance.
            $filtered ??= new ResourceMetadataCollection($resourceClass, $collection->getArrayCopy());
            $filtered[$index] = $resource->withMcp($kept);
        }

        return $filtered ?? $collection;
    }

    private function available(McpTool $tool): bool
    {
        $required = $tool->getExtraProperties()[self::REQUIRES] ?? null;
        if (null === $required) {
            return true;
        }

        if (!$required instanceof Capability) {
            throw new \LogicException(\sprintf('McpTool "%s" declares extraProperties.%s, which must be a %s case.', $tool->getName(), self::REQUIRES, Capability::class));
        }

        return $this->capabilities->has($required);
    }
}
