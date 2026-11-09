<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capability;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Agent\WebSearch\WebSearchBackend;
use App\Capability\Capabilities;
use App\Capability\Capability;
use App\Capability\CapabilityGatedResourceMetadataCollectionFactory;
use PHPUnit\Framework\TestCase;

final class CapabilityGatedResourceMetadataCollectionFactoryTest extends TestCase
{
    public function testAToolWhoseCapabilityIsMissingIsDropped(): void
    {
        $inner = $this->inner();
        $factory = new CapabilityGatedResourceMetadataCollectionFactory($inner, new Capabilities('anthropic', '', '', WebSearchBackend::Gemini));

        $this->assertSame(['open_tool'], array_keys($factory->create(\stdClass::class)[0]->getMcp() ?? []));
        $this->assertCount(2, $inner->create(\stdClass::class)[0]->getMcp() ?? [], 'the decorated (cached) collection must not be mutated');
    }

    public function testAToolWhoseCapabilityIsConfiguredStays(): void
    {
        $factory = new CapabilityGatedResourceMetadataCollectionFactory($this->inner(), new Capabilities('anthropic', 'xi-key', '', WebSearchBackend::Gemini));

        $this->assertSame(['open_tool', 'spoken_tool'], array_keys($factory->create(\stdClass::class)[0]->getMcp() ?? []));
    }

    public function testARequirementThatIsNotACapabilityFailsLoudly(): void
    {
        $inner = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $inner->method('create')->willReturn(new ResourceMetadataCollection(\stdClass::class, [
            (new ApiResource())->withMcp(['typo' => new McpTool(name: 'typo', extraProperties: ['requires' => 'voice'])]),
        ]));

        $this->expectException(\LogicException::class);
        (new CapabilityGatedResourceMetadataCollectionFactory($inner, new Capabilities('', '', '', WebSearchBackend::Gemini)))->create(\stdClass::class);
    }

    private function inner(): ResourceMetadataCollectionFactoryInterface
    {
        $collection = new ResourceMetadataCollection(\stdClass::class, [
            (new ApiResource())->withMcp([
                'open_tool' => new McpTool(name: 'open_tool'),
                'spoken_tool' => new McpTool(name: 'spoken_tool', extraProperties: ['requires' => Capability::Voice]),
            ]),
        ]);

        $inner = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $inner->method('create')->willReturn($collection);

        return $inner;
    }
}
