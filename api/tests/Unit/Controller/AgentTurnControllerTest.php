<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agent\Attachment\TurnAttachments;
use App\Agent\Dispatch\TurnDispatcher;
use App\Agent\Ui\DashboardChannel;
use App\Agent\WebSearch\WebSearchBackend;
use App\Capability\Capabilities;
use App\Controller\AgentTurnController;
use App\Dto\AgentTurnInput;
use App\Message\RunAgentTurn;
use App\Security\OwnerContext;
use App\Security\OwnerRateLimit;
use App\Storage\TurnAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validation;

/**
 * The endpoint must only ENQUEUE the turn and ack — never run the agent in the request thread.
 * That is what moves the long, multi-round-trip agent loop off the 180s request limit that killed
 * the synchronous version (MaxExecutionTimeError mid web_search).
 */
final class AgentTurnControllerTest extends TestCase
{
    private const array TURN = ['instruction' => 'Quoi prévoir ?', 'sessionId' => 'sess-1'];

    /** @var list<RunAgentTurn> */
    private array $dispatched = [];

    public function testDispatchesRunAgentTurnAndReturns202(): void
    {
        // The session topic is built from the configured public base URL (DEFAULT_URI), NOT the
        // request host (unreliable behind the proxy), and is bound to the owner. Trailing slash is
        // normalised.
        $controller = $this->controller('owner-sub', baseUri: 'https://app.example/');
        $response = $this->send($controller, [
            'instruction' => 'Quoi prévoir pour mon entretien ?',
            'sessionId' => 'sess-1',
            'selectedId' => '/garage/vehicles/01J',
            'summary' => 'Mazda6 à 250000 km',
            'pendingContext' => 'Reco huile — quel moteur ?',
        ]);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertCount(1, $this->dispatched);
        $dispatched = $this->dispatched[0];
        $this->assertSame('Quoi prévoir pour mon entretien ?', $dispatched->instruction);
        $this->assertSame((new DashboardChannel(new OwnerContext($this->securityFor('owner-sub')), 'https://app.example/', 'test-secret'))->sessionTopic('owner-sub', 'sess-1'), $dispatched->sessionTopic);
        $this->assertStringStartsWith('https://app.example/agent/sessions/', $dispatched->sessionTopic);
        // The turn carries the authenticated owner so the worker's writes are owner-scoped.
        $this->assertSame('owner-sub', $dispatched->owner);
        $this->assertSame('/garage/vehicles/01J', $dispatched->selectedId);
        $this->assertSame('Mazda6 à 250000 km', $dispatched->summary);
        $this->assertSame('Reco huile — quel moteur ?', $dispatched->pendingContext);
    }

    public function testRefusesATurnOverTheOwnersLimitWithoutDispatching(): void
    {
        $limiter = $this->limiter(1);

        $this->assertSame(Response::HTTP_ACCEPTED, $this->send($this->controller('owner-sub', $limiter), self::TURN)->getStatusCode());
        $response = $this->send($this->controller('owner-sub', $limiter), self::TURN);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter);
        $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertCount(1, $this->dispatched, 'a refused turn must not be enqueued');
    }

    public function testTheLimitIsPerOwner(): void
    {
        $limiter = $this->limiter(1);

        $this->send($this->controller('first@example.com', $limiter), self::TURN);
        $response = $this->send($this->controller('second@example.com', $limiter), self::TURN);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertCount(2, $this->dispatched);
    }

    private function controller(string $owner, ?RateLimiterFactoryInterface $limiter = null, string $baseUri = 'https://app.example'): AgentTurnController
    {
        $turns = new class($this->dispatched(...)) implements TurnDispatcher {
            public function __construct(private \Closure $record)
            {
            }

            public function dispatch(RunAgentTurn $turn): void
            {
                ($this->record)($turn);
            }
        };

        return new AgentTurnController($turns, $this->securityFor($owner), $limiter ?? $this->limiter(100), $this->limiter(1000), new DashboardChannel(new OwnerContext($this->securityFor($owner)), $baseUri, 'test-secret'), new Capabilities('test-anthropic-key', 'test-xi-key', 'test-gemini-key', WebSearchBackend::Gemini, 'test-agent-key'), new TurnAttachments($this->createStub(DocumentManager::class), new TurnAttachmentStorage($this->createStub(FilesystemOperator::class)), new MockClock()), new OwnerRateLimit(new OwnerContext($this->securityFor($owner))), $this->limiter(1000), Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    private function dispatched(RunAgentTurn $turn): void
    {
        $this->dispatched[] = $turn;
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'agent_turn', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function securityFor(string $owner): Security
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn($owner);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    /**
     * @param array<string, string> $payload
     */
    private function send(AgentTurnController $controller, array $payload): Response
    {
        return $controller(Request::create('/agent/turn', 'POST', server: ['HTTP_HOST' => 'localhost']), new AgentTurnInput(...$payload));
    }
}
