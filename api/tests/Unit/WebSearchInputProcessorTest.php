<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agent\WebSearchInputProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\MessageBag;

final class WebSearchInputProcessorTest extends TestCase
{
    public function testItDeclaresTheNativeWebSearchServerTool(): void
    {
        $input = new Input('claude-haiku-4-5', new MessageBag());

        (new WebSearchInputProcessor())->processInput($input);

        $this->assertSame(
            [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 1]],
            $input->getOptions()['server_tools'],
        );
    }

    public function testItPreservesExistingServerTools(): void
    {
        $existing = ['type' => 'code_execution_20250522', 'name' => 'code_execution'];
        $input = new Input('claude-haiku-4-5', new MessageBag(), ['server_tools' => [$existing]]);

        (new WebSearchInputProcessor())->processInput($input);

        $serverTools = $input->getOptions()['server_tools'];
        $this->assertCount(2, $serverTools);
        $this->assertSame($existing, $serverTools[0]);
        $this->assertSame('web_search', $serverTools[1]['name']);
    }

    public function testItIsIdempotentSoTheToolCallLoopDoesNotDuplicateWebSearch(): void
    {
        // The agent's tool-call loop re-invokes Agent::call with the carried-over options,
        // re-running input processors; declaring web_search twice would trip Anthropic's
        // "Tool names must be unique" error.
        $processor = new WebSearchInputProcessor();
        $input = new Input('claude-haiku-4-5', new MessageBag());

        $processor->processInput($input);
        $processor->processInput($input);

        $this->assertCount(1, $input->getOptions()['server_tools']);
    }
}
