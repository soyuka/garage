<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AgentTurnController;
use App\Message\RunAgentTurn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The endpoint must only ENQUEUE the turn and ack — never run the agent in the request thread.
 * That is what moves the long, multi-round-trip agent loop off the 180s request limit that killed
 * the synchronous version (MaxExecutionTimeError mid web_search).
 */
final class AgentTurnControllerTest extends TestCase
{
    public function testDispatchesRunAgentTurnAndReturns202(): void
    {
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            });

        // The session topic is built from the configured public base URL (DEFAULT_URI), NOT the
        // request host: the worker publishes render intents to it and the dashboard subscribes to
        // `${VITE_API_BASE}/agent/sessions/<id>`, so both must derive from the same fixed origin —
        // behind a reverse proxy the request host is unreliable. Trailing slash is normalised.
        $controller = new AgentTurnController($bus, 'https://app.example/');
        $response = $controller($this->request([
            'instruction' => 'Quoi prévoir pour mon entretien ?',
            'sessionId' => 'sess-1',
            'selectedId' => '/garage/vehicles/01J',
            'summary' => 'Mazda6 à 250000 km',
            'pendingContext' => 'Reco huile — quel moteur ?',
        ]));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertInstanceOf(RunAgentTurn::class, $dispatched);
        $this->assertSame('Quoi prévoir pour mon entretien ?', $dispatched->instruction);
        $this->assertSame('https://app.example/agent/sessions/sess-1', $dispatched->sessionTopic);
        $this->assertSame('/garage/vehicles/01J', $dispatched->selectedId);
        $this->assertSame('Mazda6 à 250000 km', $dispatched->summary);
        $this->assertSame('Reco huile — quel moteur ?', $dispatched->pendingContext);
    }

    public function testRejectsMissingFieldsWithoutDispatching(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $controller = new AgentTurnController($bus, 'https://app.example');
        $response = $controller($this->request(['instruction' => '']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
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
