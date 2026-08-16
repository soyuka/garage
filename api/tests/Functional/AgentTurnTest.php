<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use App\Tests\Mock\RecordingHub;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use App\Tests\Mock\ScriptedStreamPlatform;

/**
 * The real agent behind POST /agent/turn: a deterministic instruction drives a scripted LLM
 * (mocked platform) through the tool loop — an MCP write (log_maintenance) then a render intent
 * (toast). We assert (a) 202 ack, (b) the garage write happened, (c) a UIAction was streamed to
 * the per-session topic.
 */
final class AgentTurnTest extends AppTestCase
{
    public function testTurnRunsToolsWritesGarageAndStreamsIntent(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Scripted LLM: 1st turn → call log_maintenance; 2nd → toast; 3rd → finish.
        $platform = new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'log_maintenance', [
                    'make' => 'TORO',
                    'model' => '246-H',
                    'vehicleType' => 'groundscare',
                    'items' => [['type' => 'oil_change', 'parts' => []]],
                    'performedAt' => '2026-05-01',
                ])]),
                1 => new ToolCallResult([new ToolCall('c2', 'toast', [
                    'message' => 'Vidange enregistrée.',
                    'tone' => 'ok',
                ])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Logue une vidange sur le TORO 246-H', 'sessionId' => 'sess-abc'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        // (b) the garage write committed.
        $history = $container->get(McpToolbox::class)->execute(
            new ToolCall('h', 'get_maintenance_history', ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare']),
        )->getResult();
        $this->assertCount(1, $history['records']);

        // (c) a UIAction reached the per-session topic.
        $hub = $container->get(RecordingHub::class);
        $sessionUpdates = array_filter(
            $hub->updates,
            static fn ($u) => str_contains($u->getTopics()[0] ?? '', '/agent/sessions/sess-abc'),
        );
        $this->assertNotEmpty($sessionUpdates, 'expected a render intent on the session topic');

        $payloads = array_map(static fn ($u) => json_decode($u->getData(), true), array_values($sessionUpdates));
        $toasts = array_filter($payloads, static fn ($p) => 'toast' === ($p['type'] ?? null));
        $this->assertNotEmpty($toasts, 'expected a toast UIAction');
        $this->assertSame('Vidange enregistrée.', array_values($toasts)[0]['message']);
    }

    /**
     * The whole language chain in one pass: the browser's `Accept-Language` on POST /agent/turn →
     * `RunAgentTurn.locale` → the worker's LocaleContext → the narration the dashboard hears AND the
     * output-language directive the model is given.
     *
     * The directive is asserted through the model's own input rather than its output: a scripted
     * platform cannot decide to answer in English, so what is verifiable here is that it was TOLD to.
     */
    public function testTheInterfaceLanguageReachesTheWorkerAndTheModel(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = new \ArrayObject();
        $platform = new ScriptedStreamPlatform(static function ($model, $input) use ($seen): ToolCallResult|string {
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                $content = $message->getContent();
                foreach (\is_array($content) ? $content : [$content] as $part) {
                    if ($part instanceof Text) {
                        $seen->append($part->getText());
                    }
                }
            }

            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'list_vehicles', [])]),
                default => new ToolCallResult([new ToolCall('c2', 'end_turn', [])]),
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json', 'Accept-Language' => 'en-GB,en;q=0.9'],
            'json' => ['instruction' => 'what is due?', 'sessionId' => 'sess-en'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $prompt = implode("\n", iterator_to_array($seen));
        $this->assertStringContainsString('English', $prompt, 'the model must be told which language to answer in');
        $this->assertStringNotContainsString('user-facing string this turn in French', $prompt);

        // …and the heartbeat the user actually hears follows the same locale.
        $hub = $container->get(RecordingHub::class);
        $statuses = [];
        foreach ($hub->updates as $u) {
            $payload = json_decode($u->getData(), true);
            if ('status' === ($payload['type'] ?? null)) {
                $statuses[] = $payload['message'];
            }
        }
        $this->assertContains('Checking your garage…', $statuses);
    }
}
