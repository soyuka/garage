<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\RecordingSpeechHttpClient;
use App\Tests\Mock\RecordingTranscriptionHttpClient;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * A paid provider whose key is absent is a feature switched OFF, never a feature that fails per
 * request: the server says so on GET /agent/link and the endpoints answer without calling the vendor.
 */
final class CapabilitiesTest extends AppTestCase
{
    use McpClientTrait;

    /** @var array<string, array{env: mixed, server: mixed, getenv: string|false}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            if (null === $saved['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved['env'];
            }
            if (null === $saved['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $saved['server'];
            }
            putenv(false === $saved['getenv'] ? $name : $name.'='.$saved['getenv']);
        }
        $this->savedEnv = [];

        parent::tearDown();
    }

    public function testTheLinkAdvertisesEveryConfiguredCapability(): void
    {
        $client = static::createClient();
        $client->request('GET', '/agent/link', ['headers' => static::authHeaders()]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['agent' => true, 'voice' => true, 'dictation' => true, 'web_search' => true],
            $client->getResponse()->toArray()['capabilities'],
        );
    }

    public function testWithoutAnElevenLabsKeyVoiceAndDictationAreOff(): void
    {
        $this->unsetEnv('ELEVENLABS_API_KEY');
        $client = static::createClient();

        $client->request('GET', '/agent/link', ['headers' => static::authHeaders()]);
        $capabilities = $client->getResponse()->toArray()['capabilities'];
        $this->assertFalse($capabilities['voice']);
        $this->assertFalse($capabilities['dictation']);
        $this->assertTrue($capabilities['agent']);

        $client->request('GET', '/tts/voices', ['headers' => static::authHeaders()]);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $client->getResponse()->toArray());

        $client->request('POST', '/tts', ['json' => ['text' => 'Vidange enregistrée.'], 'headers' => static::authHeaders()]);
        $this->assertResponseStatusCodeSame(503);

        $client->request('POST', '/stt', ['body' => 'OggS-fake-opus', 'headers' => static::authHeaders() + ['Content-Type' => 'audio/webm']]);
        $this->assertResponseStatusCodeSame(503);

        $this->assertSame([], static::getContainer()->get(RecordingSpeechHttpClient::class)->requests, 'No voice credit may be spent without a key.');
        $this->assertSame([], static::getContainer()->get(RecordingTranscriptionHttpClient::class)->requests, 'No transcription credit may be spent without a key.');
    }

    public function testWithoutAnAnthropicKeyTheAgentTurnSaysItIsNotConfigured(): void
    {
        $this->unsetEnv('ANTHROPIC_API_KEY');
        $client = static::createClient();

        $client->request('GET', '/agent/link', ['headers' => static::authHeaders()]);
        $this->assertFalse($client->getResponse()->toArray()['capabilities']['agent']);

        $client->request('POST', '/agent/turn', [
            'headers' => static::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Liste mes véhicules', 'sessionId' => 'sess-no-key'],
        ]);

        $this->assertResponseStatusCodeSame(503);
        $this->assertSame(['error' => 'agent not configured'], $client->getResponse()->toArray(false));
    }

    public function testWithNoPaidKeyAtAllTheGarageMcpServerStillServesItsTools(): void
    {
        foreach (['ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'ELEVENLABS_API_KEY', 'ALIBABACLOUD_API_KEY'] as $name) {
            $this->unsetEnv($name);
        }
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('list_vehicles', $tools);
        $this->assertContains('lookup_plate', $tools);
    }

    public function testWithoutTheSelectedWebSearchBackendKeyTheAgentLosesWebSearch(): void
    {
        $this->unsetEnv('GEMINI_API_KEY');
        $client = static::createClient();

        $client->request('GET', '/agent/link', ['headers' => static::authHeaders()]);
        $this->assertFalse($client->getResponse()->toArray()['capabilities']['web_search']);

        $this->assertNotContains('web_search', $this->agentToolNames());
    }

    public function testWithTheWebSearchKeyTheAgentKeepsWebSearch(): void
    {
        static::createClient();

        $this->assertContains('web_search', $this->agentToolNames());
    }

    /**
     * @return list<string>
     */
    private function agentToolNames(): array
    {
        /** @var ToolboxInterface $toolbox */
        $toolbox = static::getContainer()->get('ai.toolbox.garage');

        return array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools());
    }

    private function unsetEnv(string $name): void
    {
        $this->savedEnv[$name] ??= ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'getenv' => getenv($name)];
        $_ENV[$name] = '';
        $_SERVER[$name] = '';
        putenv($name.'=');
    }
}
