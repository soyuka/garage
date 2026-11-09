<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capability;

use App\Capability\AgentApiKeyPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

final class AgentApiKeyPassTest extends TestCase
{
    private const PLATFORMS = [
        'anthropic' => ['api_key' => '%env(ANTHROPIC_API_KEY)%'],
        'gemini' => ['api_key' => '%env(GEMINI_API_KEY)%'],
        'generic' => [
            'qwen' => ['base_url' => '%env(QWEN_BASE_URL)%', 'api_key' => '%env(ALIBABACLOUD_API_KEY)%'],
            'local' => ['base_url' => 'http://ollama:11434'],
        ],
    ];

    public function testTheAnthropicAgentNeedsTheAnthropicKey(): void
    {
        self::assertSame('%env(ANTHROPIC_API_KEY)%', $this->keyFor(['ai' => ['platform' => self::PLATFORMS, 'agent' => ['garage' => ['platform' => 'ai.platform.anthropic']]]]));
    }

    public function testTheQwenAgentNeedsTheAlibabaKey(): void
    {
        self::assertSame('%env(ALIBABACLOUD_API_KEY)%', $this->keyFor(['ai' => ['platform' => self::PLATFORMS, 'agent' => ['garage' => ['platform' => 'ai.platform.generic.qwen']]]]));
    }

    public function testALaterConfigurationWins(): void
    {
        self::assertSame('%env(ALIBABACLOUD_API_KEY)%', $this->keyFor(
            ['ai' => ['platform' => self::PLATFORMS, 'agent' => ['garage' => ['platform' => 'ai.platform.anthropic']]]],
            ['ai' => ['agent' => ['garage' => ['platform' => 'ai.platform.generic.qwen']]]],
        ));
    }

    public function testAKeylessPlatformNeedsNoKey(): void
    {
        self::assertNull($this->keyFor(['ai' => ['platform' => self::PLATFORMS, 'agent' => ['garage' => ['platform' => 'ai.platform.generic.local']]]]));
    }

    public function testAnUnconfiguredPlatformIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ai.platform.mistral');

        $this->keyFor(['ai' => ['platform' => self::PLATFORMS, 'agent' => ['garage' => ['platform' => 'ai.platform.mistral']]]]);
    }

    public function testAnUnconfiguredAgentIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"garage"');

        $this->keyFor(['ai' => ['platform' => self::PLATFORMS, 'agent' => []]]);
    }

    /**
     * @param array{ai: array<string, mixed>} ...$configs in load order
     */
    private function keyFor(array ...$configs): mixed
    {
        $container = new ContainerBuilder();
        foreach (array_reverse($configs) as $config) {
            $container->prependExtensionConfig('ai', $config['ai']);
        }

        (new AgentApiKeyPass('garage'))->process($container);

        return $container->getParameter('app.agent.api_key');
    }
}
