<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agent\Dispatch\TurnDispatcher;
use App\Agent\Finder\TurnImageDecoder;
use App\Agent\Finder\TurnImageStash;
use App\Agent\TurnSurface;
use App\Controller\AgentTurnController;
use App\Message\RunAgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

final class AgentTurnControllerTest extends TestCase
{
    public function testDispatchesRunAgentTurnAndReturns202(): void
    {
        $dispatcher = $this->dispatcher();

        // The session topic is built from the configured public base URL (DEFAULT_URI), NOT the
        // request host: behind a reverse proxy the request host is unreliable. Trailing slash is normalised.
        $response = $this->controller($dispatcher, 'https://app.example/')($this->request([
            'instruction' => 'filtre à huile',
            'sessionId' => 'sess-1',
            'summary' => 'Mazda6 à 250000 km',
            'pendingContext' => 'Reco huile — quel moteur ?',
        ]));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertCount(1, $dispatcher->turns);
        $dispatched = $dispatcher->turns[0];
        $this->assertSame('filtre à huile', $dispatched->instruction);
        $this->assertSame('https://app.example/agent/sessions/sess-1', $dispatched->sessionTopic);
        $this->assertSame('owner-sub', $dispatched->owner);
        $this->assertSame(TurnSurface::Finder, $dispatched->surface);
        $this->assertSame('Mazda6 à 250000 km', $dispatched->summary);
        $this->assertSame('Reco huile — quel moteur ?', $dispatched->pendingContext);
    }

    public function testASelectedIdInThePayloadIsIgnored(): void
    {
        $dispatcher = $this->dispatcher();

        $response = $this->controller($dispatcher)($this->request([
            'instruction' => 'filtre à huile',
            'sessionId' => 'sess-1',
            'selectedId' => '/garage/vehicles/01J',
        ]));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertFalse(property_exists($dispatcher->turns[0], 'selectedId'));
    }

    public function testRejectsMissingFieldsWithoutDispatching(): void
    {
        $dispatcher = $this->dispatcher();

        $response = $this->controller($dispatcher)($this->request(['instruction' => '']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $dispatcher->turns);
    }

    public function testRejectsAnUnknownSurfaceWithoutDispatching(): void
    {
        $dispatcher = $this->dispatcher();

        $response = $this->controller($dispatcher)($this->request(['instruction' => 'x', 'sessionId' => 's', 'surface' => 'garage']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $dispatcher->turns);
    }

    private function dispatcher(): TurnDispatcher
    {
        return new class implements TurnDispatcher {
            /** @var list<RunAgentTurn> */
            public array $turns = [];

            public function dispatch(RunAgentTurn $turn): void
            {
                $this->turns[] = $turn;
            }
        };
    }

    private function controller(TurnDispatcher $dispatcher, string $baseUri = 'https://app.example'): AgentTurnController
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('owner-sub');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AgentTurnController(
            $dispatcher,
            $security,
            new TurnImageDecoder(),
            new TurnImageStash($this->createStub(DocumentManager::class), $this->createStub(ClockInterface::class)),
            $baseUri,
        );
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
