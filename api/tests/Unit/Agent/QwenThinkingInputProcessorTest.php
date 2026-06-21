<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\QwenThinkingInputProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class QwenThinkingInputProcessorTest extends TestCase
{
    public function testDisablesThinkingOnQwen(): void
    {
        $input = new Input('qwen3.5-flash', new MessageBag(Message::ofUser('quelle viscosité ?')));

        (new QwenThinkingInputProcessor())->processInput($input);

        $this->assertFalse($input->getOptions()['enable_thinking']);
    }

    public function testDoesNotTouchAnthropic(): void
    {
        // enable_thinking is a DashScope-only body flag; Anthropic would reject it.
        $input = new Input('claude-haiku-4-5', new MessageBag(Message::ofUser('quelle viscosité ?')));

        (new QwenThinkingInputProcessor())->processInput($input);

        $this->assertArrayNotHasKey('enable_thinking', $input->getOptions());
    }

    public function testPreservesExistingOptions(): void
    {
        $input = new Input('qwen3.5-flash', new MessageBag(Message::ofUser('hi')), ['enable_search' => true]);

        (new QwenThinkingInputProcessor())->processInput($input);

        $options = $input->getOptions();
        $this->assertTrue($options['enable_search']);
        $this->assertFalse($options['enable_thinking']);
    }
}
