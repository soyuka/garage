<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderVehicle;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
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

        // Scripted LLM: 1st call → a show_parts tool call; 2nd → finish.
        $platform = new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Le filtre à huile se change à chaque vidange.'])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $handler = $container->get(RunAgentTurnHandler::class);
        $handler(new RunAgentTurn(
            instruction: 'Quelle viscosité pour la Mazda 6 ?',
            sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-journal'),
            owner: self::TEST_OWNER,
            vehicle: new FinderVehicle(19107),
        ));

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns, 'the turn must persist exactly one journal entry');

        $turn = $turns[0];
        self::assertSame('Quelle viscosité pour la Mazda 6 ?', $turn->getInstruction());
        self::assertSame(self::TEST_OWNER, $turn->getOwner());
        self::assertSame(AgentTurnStatus::Completed, $turn->getStatus());
        self::assertStringContainsString('Quelle viscosité', $turn->getContext(), 'the built LLM context is captured');

        $toolCalls = $turn->getToolCalls();
        self::assertCount(1, $toolCalls, 'the show_parts tool call must be journalled');
        self::assertSame('show_parts', $toolCalls[0]->getName());
        self::assertSame(100259, $toolCalls[0]->getArguments()['categoryId']);
    }

    public function testFailedTurnIsJournalledAsFailedWithItsError(): void
    {
        static::createClient();
        $container = static::getContainer();

        // The agent loop throws: the journal must still record the turn, flagged Failed with the error.
        $platform = new ScriptedStreamPlatform(static function (): string {
            throw new \RuntimeException('boom');
        });
        $container->set('ai.platform.anthropic', $platform);

        $handler = $container->get(RunAgentTurnHandler::class);
        try {
            $handler(new RunAgentTurn(
                instruction: 'plante',
                sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-fail'),
                owner: self::TEST_OWNER,
            ));
        } catch (\Throwable) {
            // The handler rethrows so Messenger can retry; the journal records it first (finally block).
        }

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(AgentTurnStatus::Failed, $turns[0]->getStatus());
        self::assertStringContainsString('boom', (string) $turns[0]->getError());
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
}
