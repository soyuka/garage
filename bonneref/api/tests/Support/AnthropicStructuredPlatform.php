<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The real Anthropic bridge (request encoding + result converter) and the structured-output
 * subscriber the AI bundle registers, over a canned HTTP response, so a reader exercises the
 * result type production actually yields for `response_format`.
 */
final class AnthropicStructuredPlatform
{
    /**
     * @param array<string, mixed>|null $request filled with the decoded request body
     */
    public static function answering(string $text, ?array &$request = null): PlatformInterface
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($text, &$request): MockResponse {
            $request = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-5-5',
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return Factory::createPlatform(
            'test-key',
            $client,
            new ModelCatalog(['claude-sonnet-5-5' => [
                'class' => Claude::class,
                'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::INPUT_IMAGE, Capability::INPUT_PDF],
            ]]),
            eventDispatcher: $dispatcher,
        );
    }
}
