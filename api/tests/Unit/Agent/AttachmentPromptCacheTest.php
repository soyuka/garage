<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An attached PDF or image rides the user message of every tool round. The bridge only marks the last
 * block of the last user message, which from round two on is a tool result, so the attachment must
 * carry a breakpoint of its own for each later round to read it from the cache.
 */
final class AttachmentPromptCacheTest extends KernelTestCase
{
    private const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    public function testALaterToolRoundReadsTheAttachmentFromTheCache(): void
    {
        $body = $this->send(new MessageBag(
            Message::forSystem('You are the workshop assistant.'),
            Message::ofUser(new Text('Que dit cette facture ?'), new Document(self::PDF, 'application/pdf', 'facture.pdf'), new Image(self::PNG, 'image/png', 'photo.png')),
            Message::ofAssistant(new ToolCall('t1', 'list_vehicles', [])),
            Message::ofToolCall(new ToolCall('t1', 'list_vehicles', []), '[]'),
        ));

        $attachmentMessage = $body['messages'][0]['content'];
        $this->assertSame('image', $attachmentMessage[2]['type']);
        $this->assertSame(['type' => 'ephemeral'], $attachmentMessage[2]['cache_control'] ?? null, 'the last attachment block closes a cached prefix');
        $this->assertArrayNotHasKey('cache_control', $attachmentMessage[0]);
        $this->assertArrayNotHasKey('cache_control', $attachmentMessage[1]);
        $this->assertLessThanOrEqual(4, substr_count(json_encode($body, \JSON_THROW_ON_ERROR), '"cache_control"'), 'Anthropic refuses more than 4 breakpoints');
    }

    public function testTheFirstRoundSpendsNoExtraBreakpoint(): void
    {
        $body = $this->send(new MessageBag(
            Message::forSystem('You are the workshop assistant.'),
            Message::ofUser(new Text('Que dit cette facture ?'), new Document(self::PDF, 'application/pdf', 'facture.pdf')),
        ));

        $this->assertSame(['type' => 'ephemeral'], $body['messages'][0]['content'][1]['cache_control'] ?? null);
        $this->assertSame(3, substr_count(json_encode($body, \JSON_THROW_ON_ERROR), '"cache_control"'), 'tools, system, attachment');
    }

    public function testAPlainTextMessageIsLeftToTheBridge(): void
    {
        $body = $this->send(new MessageBag(
            Message::ofUser(new Text('Bonjour'), new Text('Quoi prévoir ?')),
            Message::ofAssistant(new ToolCall('t1', 'list_vehicles', [])),
            Message::ofToolCall(new ToolCall('t1', 'list_vehicles', []), '[]'),
        ));

        $this->assertStringNotContainsString('cache_control', json_encode($body['messages'][0], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed> the decoded request body
     */
    private function send(MessageBag $messages): array
    {
        $contract = static::getContainer()->get('ai.platform.contract.anthropic');
        $this->assertInstanceOf(Contract::class, $contract);

        $body = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-5-5',
                'content' => [['type' => 'text', 'text' => 'Fait.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $platform = Factory::createPlatform('test-key', $http, new ModelCatalog(['claude-sonnet-5-5' => [
            'class' => Claude::class,
            'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING, Capability::INPUT_IMAGE, Capability::INPUT_PDF],
        ]]), $contract);
        $platform->invoke('claude-sonnet-5-5', $messages, ['tools' => [new Tool(new ExecutionReference(self::class, 'send'), 'list_vehicles', 'List the garage.')]])->asText();

        $this->assertIsArray($body);

        return $body;
    }
}
