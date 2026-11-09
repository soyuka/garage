<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Journal\TurnJournal;
use App\Agent\TurnSettlement;
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
        self::assertCount(1, $turns, 'the turn must persist exactly one journal entry');

        $turn = $turns[0];
        self::assertSame('Quelle viscosité pour la Mazda 6 ?', $turn->getInstruction());
        self::assertSame(self::TEST_OWNER, $turn->getOwner());
        self::assertSame(AgentTurnStatus::Completed, $turn->getStatus());
        self::assertStringContainsString('Quelle viscosité', $turn->getContext(), 'the built LLM context is captured');

        $toolCalls = $turn->getToolCalls();
        self::assertCount(1, $toolCalls, 'the toast tool call must be journalled');
        self::assertSame('toast', $toolCalls[0]->getName());
        self::assertSame('Viscosité 5W-30.', $toolCalls[0]->getArguments()['message']);
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
        } catch (\Throwable) {
            // The handler rethrows so Messenger can retry; the journal records it first (finally block).
        }

        $response = $client->request('GET', '/agent/turns', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $body = $response->getContent();
        foreach (['/app/var', 'DATABASE_URL', 's3cr3t', 'secret.key'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        $data = $response->toArray();
        $turn = ($data['member'] ?? $data['hydra:member'])[0];
        self::assertSame('failed', $turn['status']);
        self::assertSame('The turn failed.', $turn['error']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $turn['errorId']);

        $logged = array_values(array_filter($logger->records, static fn (array $r): bool => 'error' === $r['level'] && ($r['context']['errorId'] ?? null) === $turn['errorId']));
        self::assertCount(1, $logged);
        self::assertSame($leak, $logged[0]['context']['exception']->getMessage());
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
        self::assertCount(1, $members, 'a user sees only their own turns');
        self::assertSame('ma question', $members[0]['instruction']);
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
        self::assertCount(1, $members);
        self::assertSame('vidange faite', $members[0]['instruction']);
        foreach (['surface', 'kind', 'search', 'route', 'vehicle', 'categoryLabel', 'images', 'partsApiCalls', 'partsApiCallsByMethod'] as $finderField) {
            self::assertArrayNotHasKey($finderField, $members[0]);
        }
    }
}
