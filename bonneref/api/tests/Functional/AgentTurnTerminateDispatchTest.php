<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;
use App\Agent\TurnSettlement;
use App\Agent\Ui\TurnSessionTopic;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class AgentTurnTerminateDispatchTest extends AppTestCase
{
    private string $previousMode;

    protected function setUp(): void
    {
        $this->previousMode = $_SERVER['AGENT_TURN_DISPATCH'];
        $_SERVER['AGENT_TURN_DISPATCH'] = $_ENV['AGENT_TURN_DISPATCH'] = 'terminate';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // .env sets the mode, so the next test's kernel must find it back, not an unset variable.
        $_SERVER['AGENT_TURN_DISPATCH'] = $_ENV['AGENT_TURN_DISPATCH'] = $this->previousMode;

        parent::tearDown();
    }

    public function testTurnRunsOnlyOnceTheKernelTerminates(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    return 'Fait.';
                }
            }

            return new ToolCallResult([new ToolCall('c1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Le filtre à huile se change à chaque vidange.'])]);
        }));

        [$kernel, $request] = self::turnRequest('sess-deferred');
        $response = $kernel->handle($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([], self::frames('sess-deferred'), 'the turn must not run before the response is sent');
        self::assertSame([], $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10));

        $kernel->terminate($request, $response);

        $frames = self::frames('sess-deferred');
        self::assertSame(['type' => 'turn', 'phase' => 'start'], $frames[0]);
        self::assertSame(['type' => 'turn', 'phase' => 'end'], $frames[array_key_last($frames)]);
        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(AgentTurnStatus::Completed, $turns[0]->getStatus());
    }

    public function testCrashedTurnIsJournalledFailedAndStillEndsTheTurn(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function (): string {
            throw new \RuntimeException('boom');
        }));

        [$kernel, $request] = self::turnRequest('sess-deferred-boom');
        $response = $kernel->handle($request);
        self::assertSame(202, $response->getStatusCode());

        $kernel->terminate($request, $response);

        $frames = self::frames('sess-deferred-boom');
        self::assertSame(['type' => 'turn', 'phase' => 'end', 'outcome' => 'failed'], $frames[array_key_last($frames)]);
        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(AgentTurnStatus::Failed, $turns[0]->getStatus());
        self::assertSame(TurnSettlement::PUBLIC_ERROR, $turns[0]->getError());
        self::assertNotNull($turns[0]->getErrorId());
    }

    public function testTurnFailingBeforeItsLoopStillEndsFailed(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set(TurnCancellations::class, new class implements TurnCancellations {
            public function request(string $sessionId): void
            {
            }

            public function isRequested(string $sessionId): bool
            {
                return false;
            }

            public function clear(string $sessionId): void
            {
                throw new \RuntimeException('The file "/app/config/prompts/finder.md" does not exist or is not readable.');
            }
        });

        [$kernel, $request] = self::turnRequest('sess-deferred-early');
        $response = $kernel->handle($request);
        self::assertSame(202, $response->getStatusCode());

        $kernel->terminate($request, $response);

        self::assertSame([['type' => 'turn', 'phase' => 'end', 'outcome' => 'failed']], self::frames('sess-deferred-early'));
        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(AgentTurnStatus::Failed, $turns[0]->getStatus());
        self::assertSame(TurnSettlement::PUBLIC_ERROR, $turns[0]->getError());
        self::assertNotNull($turns[0]->getErrorId());
    }

    public function testTurnKilledByAFatalIsEndedFailedAtShutdown(): void
    {
        static::createClient();
        $container = static::getContainer();
        $settlement = $container->get(TurnSettlement::class);
        $turn = new RunAgentTurn(
            owner: self::TEST_OWNER,
            instruction: 'Quelle viscosité ?',
            sessionTopic: 'https://localhost/agent/sessions/sess-fatal',
        );

        $settlement->open($turn);
        $settlement->onShutdown(['type' => \E_ERROR, 'message' => 'Maximum execution time of 120 seconds exceeded', 'file' => '', 'line' => 0]);

        self::assertSame([['type' => 'turn', 'phase' => 'end', 'outcome' => 'failed']], self::frames('sess-fatal'));
        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(AgentTurnStatus::Failed, $turns[0]->getStatus());
        self::assertSame(TurnSettlement::PUBLIC_ERROR, $turns[0]->getError());
        self::assertNotNull($turns[0]->getErrorId());

        $settlement->onShutdown(null);
        self::assertCount(1, self::frames('sess-fatal'), 'a settled turn is not ended twice');
    }

    /**
     * @return array{KernelInterface&TerminableInterface, Request}
     */
    private static function turnRequest(string $sessionId): array
    {
        $request = Request::create(
            '/agent/turn',
            'POST',
            server: [
                'HTTP_AUTHORIZATION' => self::authHeaders()['Authorization'],
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
            ],
            content: json_encode(['instruction' => 'Quelle viscosité ?', 'sessionId' => $sessionId, 'vehicle' => ['carId' => 19107, 'carType' => 'PC']], \JSON_THROW_ON_ERROR),
        );

        $kernel = static::$kernel;
        self::assertInstanceOf(TerminableInterface::class, $kernel);

        return [$kernel, $request];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function frames(string $session): array
    {
        // A turn posted over HTTP streams on the owner-bound key; one run through the handler keeps its raw topic.
        $key = static::getContainer()->get(TurnSessionTopic::class)->key(self::TEST_OWNER, $session);
        $updates = array_filter(
            static::getContainer()->get(RecordingHub::class)->updates,
            static fn ($u): bool => \in_array(substr($u->getTopics()[0] ?? '', (int) strrpos($u->getTopics()[0] ?? '', '/') + 1), [$session, $key], true),
        );

        return array_values(array_map(static fn ($u): array => json_decode($u->getData(), true), $updates));
    }
}
