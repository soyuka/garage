<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agent\WebSearchInputProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;

final class WebSearchInputProcessorTest extends TestCase
{
    public function testItDoesNotDeclareWebSearchBeforeAnyLocalToolHasRun(): void
    {
        // First pass of a turn: only the user instruction is in the bag, no tool has run yet.
        // Web search is a last resort — it must not be on the menu until the agent has tried the
        // local garage/catalog tools at least once.
        $input = new Input('claude-haiku-4-5', new MessageBag(Message::ofUser('quelle huile ?')));

        (new WebSearchInputProcessor())->processInput($input);

        $this->assertArrayNotHasKey('server_tools', $input->getOptions());
    }

    public function testItDeclaresTheNativeWebSearchOnceALocalToolHasRun(): void
    {
        // A tool round-trip has completed (a ToolCallMessage carries its result), so the local
        // tools came up short — now web_search is allowed as the fallback.
        $input = new Input('claude-haiku-4-5', $this->bagWithToolResult());

        (new WebSearchInputProcessor())->processInput($input);

        $this->assertSame(
            [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 1]],
            $input->getOptions()['server_tools'],
        );
    }

    public function testItPreservesExistingServerTools(): void
    {
        $existing = ['type' => 'code_execution_20250522', 'name' => 'code_execution'];
        $input = new Input('claude-haiku-4-5', $this->bagWithToolResult(), ['server_tools' => [$existing]]);

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
        $input = new Input('claude-haiku-4-5', $this->bagWithToolResult());

        $processor->processInput($input);
        $processor->processInput($input);

        $this->assertCount(1, $input->getOptions()['server_tools']);
    }

    private function bagWithToolResult(): MessageBag
    {
        return new MessageBag(
            Message::ofUser('quelle huile ?'),
            new ToolCallMessage(new ToolCall('c1', 'get_vehicle_facts', []), '{"facts":[]}'),
        );
    }
}
