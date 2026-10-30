<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\I18n\AgentMessages;
use App\I18n\Locale;
use App\Tests\Mock\RecordingHub;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use App\Tests\Mock\ScriptedStreamPlatform;

final class AgentTurnTest extends AppTestCase
{
    /**
     * The browser's `Accept-Language` on POST /agent/turn → `RunAgentTurn.locale` → the worker's
     * LocaleContext → the narration the page hears.
     */
    public function testTheInterfaceLanguageReachesTheWorkerAndTheNarration(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $platform = new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'list_makes', [])]),
                default => new ToolCallResult([new ToolCall('c2', 'end_turn', [])]),
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json', 'Accept-Language' => 'en-GB,en;q=0.9'],
            'json' => ['instruction' => 'what is due?', 'sessionId' => 'sess-en'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $hub = $container->get(RecordingHub::class);
        $statuses = [];
        foreach ($hub->updates as $u) {
            $payload = json_decode($u->getData(), true);
            if ('status' === ($payload['type'] ?? null)) {
                $statuses[] = $payload['message'];
            }
        }
        $this->assertContains(AgentMessages::for(Locale::En)->finderNarration['list_makes'], $statuses, 'the list_makes heartbeat is the English sentence');
        $this->assertNotContains(AgentMessages::for(Locale::Fr)->finderNarration['list_makes'], $statuses, 'no French sentence leaks into an English turn');
    }
}
