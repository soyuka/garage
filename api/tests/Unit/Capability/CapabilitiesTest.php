<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capability;

use App\Agent\WebSearch\WebSearchBackend;
use App\Capability\Capabilities;
use App\Capability\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testTheAgentFollowsItsPlatformKeyNotTheAnthropicOne(): void
    {
        $this->assertFalse((new Capabilities('anthropic-key', '', '', WebSearchBackend::Gemini, agentApiKey: ''))->has(Capability::Agent));
        // @mago-expect lint:no-literal-password test fixture
        $this->assertTrue((new Capabilities('', '', '', WebSearchBackend::Gemini, agentApiKey: 'qwen-key'))->has(Capability::Agent));
    }

    public function testAnAgentOnAKeylessPlatformIsOn(): void
    {
        $this->assertTrue((new Capabilities('', '', '', WebSearchBackend::Gemini, agentApiKey: null))->has(Capability::Agent));
    }
}
