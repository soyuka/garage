<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agent\Dispatch\TurnDispatcher;
use App\Agent\WebSearch\WebSearchBackend;
use App\Capability\Capabilities;
use App\Controller\AgentTurnController;
use App\Message\RunAgentTurn;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The endpoint must only ENQUEUE the turn and ack — never run the agent in the request thread.
 * That is what moves the long, multi-round-trip agent loop off the 180s request limit that killed
 * the synchronous version (MaxExecutionTimeError mid web_search).
 */
final class AgentTurnControllerTest extends TestCase
{
    /** @var list<RunAgentTurn> */
    private array $dispatched = [];

    public function testDispatchesRunAgentTurnAndReturns202(): void
    {
        // The session topic is built from the configured public base URL (DEFAULT_URI), NOT the
        // request host: the worker publishes render intents to it and the dashboard subscribes to
        // `${VITE_API_BASE}/agent/sessions/<id>`, so both must derive from the same fixed origin —
        // behind a reverse proxy the request host is unreliable. Trailing slash is normalised.
        $controller = $this->controller('owner-sub', baseUri: 'https://app.example/');
        $response = $controller($this->request([
            'instruction' => 'Quoi prévoir pour mon entretien ?',
            'sessionId' => 'sess-1',
            'selectedId' => '/garage/vehicles/01J',
            'summary' => 'Mazda6 à 250000 km',
            'pendingContext' => 'Reco huile — quel moteur ?',
        ]));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertCount(1, $this->dispatched);
        $dispatched = $this->dispatched[0];
        $this->assertSame('Quoi prévoir pour mon entretien ?', $dispatched->instruction);
        $this->assertSame('https://app.example/agent/sessions/sess-1', $dispatched->sessionTopic);
        // The turn carries the authenticated owner so the worker's writes are owner-scoped.
        $this->assertSame('owner-sub', $dispatched->owner);
        $this->assertSame('/garage/vehicles/01J', $dispatched->selectedId);
        $this->assertSame('Mazda6 à 250000 km', $dispatched->summary);
        $this->assertSame('Reco huile — quel moteur ?', $dispatched->pendingContext);
    }

    public function testRejectsMissingFieldsWithoutDispatching(): void
    {
        $response = $this->controller('owner-sub')($this->request(['instruction' => '']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $this->dispatched);
    }

    public function testRefusesATurnOverTheOwnersLimitWithoutDispatching(): void
    {
        $limiter = $this->limiter(1);

        $this->assertSame(Response::HTTP_ACCEPTED, $this->controller('owner-sub', $limiter)($this->turn())->getStatusCode());
        $response = $this->controller('owner-sub', $limiter)($this->turn());

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

        $this->controller('first@example.com', $limiter)($this->turn());
        $response = $this->controller('second@example.com', $limiter)($this->turn());

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertCount(2, $this->dispatched);
    }

    public function testAnInvalidRequestDoesNotSpendTheBudget(): void
    {
        $limiter = $this->limiter(1);

        $this->controller('owner-sub', $limiter)($this->request(['instruction' => '']));
        $response = $this->controller('owner-sub', $limiter)($this->turn());

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
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

        return new AgentTurnController($turns, $this->securityFor($owner), $limiter ?? $this->limiter(100), $baseUri, new Capabilities('test-anthropic-key', 'test-xi-key', 'test-gemini-key', WebSearchBackend::Gemini));
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

    private function turn(): Request
    {
        return $this->request(['instruction' => 'Quoi prévoir ?', 'sessionId' => 'sess-1']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        return Request::create(
            '/agent/turn',
            'POST',
            server: ['HTTP_HOST' => 'localhost'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }
}
