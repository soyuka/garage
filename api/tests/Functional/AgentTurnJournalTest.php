<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Journal\TurnJournal;
use App\Agent\TurnSettlement;
use App\Agent\Ui\DashboardChannel;
use App\Agent\Ui\RenderIntentTools;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\RecordingLogger;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * A turn is otherwise stateless — nothing about it survives the worker. The journal persists one
 * {@see AgentTurn} per turn so the operator can replay what the agent actually did: the instruction,
 * the built context, every tool call with its arguments and result, the web-search spend, the token
 * usage and the final status. This is the debug surface; without it the turn is a black box.
 */
final class AgentTurnJournalTest extends AppTestCase
{
    public function testTurnPersistsAJournalEntryWithItsToolCalls(): void
    {
        static::createClient();
        $container = static::getContainer();

        // Scripted LLM: 1st call → a toast tool call; 2nd → finish.
        $platform = new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'toast', ['message' => 'Viscosité 5W-30.', 'tone' => 'ok'])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $handler = $container->get(RunAgentTurnHandler::class);
        $handler(new RunAgentTurn(
            instruction: 'Quelle viscosité pour la Mazda 6 ?',
            sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-journal'),
            owner: self::TEST_OWNER,
        ));

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        $this->assertCount(1, $turns, 'the turn must persist exactly one journal entry');

        $turn = $turns[0];
        $this->assertSame('Quelle viscosité pour la Mazda 6 ?', $turn->getInstruction());
        $this->assertSame(self::TEST_OWNER, $turn->getOwner());
        $this->assertSame(AgentTurnStatus::Completed, $turn->getStatus());
        $this->assertStringContainsString('Quelle viscosité', $turn->getContext(), 'the built LLM context is captured');

        $toolCalls = $turn->getToolCalls();
        $this->assertCount(1, $toolCalls, 'the toast tool call must be journalled');
        $this->assertSame('toast', $toolCalls[0]->getName());
        $this->assertSame('Viscosité 5W-30.', $toolCalls[0]->getArguments()['message']);
    }

    public function testTheJournalKeysATurnOnItsSessionNotOnItsOwnersSessionPrefix(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'Fait.'));
        $channel = $container->get(DashboardChannel::class);

        $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: 'Bonjour',
            sessionTopic: $channel->sessionTopic(self::TEST_OWNER, 'tab-one'),
            owner: self::TEST_OWNER,
        ));

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        $this->assertCount(1, $turns);
        $this->assertSame($channel->sessionKey(self::TEST_OWNER, 'tab-one'), $turns[0]->getSessionId());
    }

    public function testAFailedToolCallJournalsItsPublicErrorTextNeverTheRawCause(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $leak = 'connect to http://bonneref:8444 with Bearer s3cr3t-token';
        static::bonneref()->route('/vehicles/TT000TT', new TransportException($leak));

        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([
                    new ToolCall('c1', 'lookup_plate', ['plate' => 'TT-000-TT']),
                    new ToolCall('c2', 'focus_view', ['panel' => 's3cr3t-panel']),
                ]),
                default => 'Fait.',
            };
        }));

        $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: 'Cherche la plaque TT-000-TT',
            sessionTopic: 'https://localhost/agent/sessions/sess-tool-fail',
            owner: self::TEST_OWNER,
        ));

        $results = [];
        foreach ($container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10)[0]->getToolCalls() as $call) {
            $results[$call->getName()] = $call->getResult();
        }
        $this->assertStringStartsWith('upstream_unavailable', (string) json_decode($results['lookup_plate'], true)['content'][0]['text']);
        $this->assertSame('An error occurred while executing tool "focus_view".', $results['focus_view']);

        $body = $client->request('GET', '/agent/turns', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->getContent();
        foreach (['s3cr3t', 'bonneref:8444', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, str_replace('s3cr3t-panel', '', $body));
        }
    }

    public function testAFailedTurnJournalsAPublicMessageAndLogsTheCauseUnderItsId(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $logger = new RecordingLogger();
        $container->set(TurnSettlement::class, new TurnSettlement($container->get(TurnJournal::class), $container->get(RenderIntentTools::class), $logger));

        $leak = 'cannot open /app/var/secret.key using %env(DATABASE_URL)% APP_SECRET=s3cr3t';
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use ($leak): string {
            throw new \RuntimeException($leak);
        }));

        try {
            $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
                instruction: 'plante',
                sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-fail'),
                owner: self::TEST_OWNER,
            ));
            // @mago-expect lint:no-empty-catch-clause the handler rethrows by design; the journal row is asserted below
        } catch (\Throwable) {
        }

        $response = $client->request('GET', '/agent/turns', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $body = $response->getContent();
        foreach (['/app/var', 'DATABASE_URL', 's3cr3t', 'secret.key'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $data = $response->toArray();
        $turn = ($data['member'] ?? $data['hydra:member'])[0];
        $this->assertSame('failed', $turn['status']);
        $this->assertSame('The turn failed.', $turn['error']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $turn['errorId']);

        $logged = array_values(array_filter($logger->records, static fn (array $r): bool => 'error' === $r['level'] && ($r['context']['errorId'] ?? null) === $turn['errorId']));
        $this->assertCount(1, $logged);
        $this->assertSame($leak, $logged[0]['context']['exception']->getMessage());
    }

    public function testJournalIsReadableOverHttpAndOwnerScoped(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Seed one turn owned by the test user, one owned by a stranger.
        $dm = $container->get(DocumentManager::class);
        $mine = new AgentTurn(self::TEST_OWNER, 'sess-mine', 'ma question', 'le contexte', 'anthropic', 'claude-haiku-4-5', true);
        $mine->finish(AgentTurnStatus::Completed, null);
        $theirs = new AgentTurn('stranger', 'sess-theirs', 'leur question', 'autre contexte', 'anthropic', 'claude-haiku-4-5', true);
        $theirs->finish(AgentTurnStatus::Completed, null);
        $dm->persist($mine);
        $dm->persist($theirs);
        $dm->flush();

        $response = $client->request('GET', '/agent/turns', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json'],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $members = $data['member'] ?? $data['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'a user sees only their own turns');
        $this->assertSame('ma question', $members[0]['instruction']);
    }

    public function testARowWrittenWhileTheFinderSharedTheJournalStillLists(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);

        // The shape every garage row carried until 2026-11-02, finder-only fields included: production
        // keeps them until the migration in FUTURE.md runs, so they must hydrate and stay unexposed.
        $dm->getDocumentCollection(AgentTurn::class)->insertOne([
            '_id' => '01KVMQT4GGYZ9XJJG6QJA15RA2',
            'owner' => self::TEST_OWNER,
            'sessionId' => 'sess-legacy',
            'instruction' => 'vidange faite',
            'context' => 'le contexte',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'stream' => true,
            'selectedId' => null,
            'summary' => null,
            'pendingContext' => null,
            'status' => 'completed',
            'error' => null,
            'startedAt' => new UTCDateTime(),
            'finishedAt' => new UTCDateTime(),
            'durationMs' => 6526,
            'toolCalls' => [],
            'webSearchCount' => 0,
            'webSearchQueries' => [],
            'rawCalls' => [],
            'surface' => 'garage',
            'kind' => 'agent',
            'search' => [],
            'images' => [],
            'partsApiCallsByMethod' => [],
            'hiddenFromHistory' => false,
        ]);

        $response = $client->request('GET', '/agent/turns', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json'],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $members = $data['member'] ?? $data['hydra:member'] ?? [];
        $this->assertCount(1, $members);
        $this->assertSame('vidange faite', $members[0]['instruction']);
        foreach (['surface', 'kind', 'search', 'route', 'vehicle', 'categoryLabel', 'images', 'partsApiCalls', 'partsApiCallsByMethod'] as $finderField) {
            $this->assertArrayNotHasKey($finderField, $members[0]);
        }
    }
}
