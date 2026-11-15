<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Ui\TurnSessionTopic;
use App\Security\OwnerContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the page the Mercure topic its turns stream on, `?session=<tab id>`: a digest of (owner, id)
 * the browser cannot compute ({@see TurnSessionTopic}), and the short-lived subscriber token that
 * opens exactly that topic. Frames are published private, so without it the hub delivers nothing.
 */
final readonly class AgentLinkController
{
    public function __construct(
        private TurnSessionTopic $sessions,
        private OwnerContext $owner,
        #[Target('default')]
        private TokenFactoryInterface $subscriberTokens,
        private ClockInterface $clock,
        #[Autowire('%env(int:MERCURE_SUBSCRIBER_TOKEN_TTL)%')]
        private int $tokenTtl,
    ) {
    }

    #[Route('/agent/link', name: 'agent_link', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->query->get('session', '');
        if (!TurnSessionTopic::isValidId($session)) {
            return new JsonResponse(['error' => 'session must be 1 to 64 letters, digits, "-" or "_"'], Response::HTTP_BAD_REQUEST);
        }

        $topic = $this->sessions->topic($this->owner->require(), $session);
        $expiresAt = new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() + $this->tokenTtl));
        $token = $this->subscriberTokens->create([new Grant([Grant::ACTION_SUBSCRIBE], [$topic])], ['exp' => $expiresAt]);

        return new JsonResponse(['sessionTopic' => $topic, 'subscriberToken' => $token, 'expiresAt' => $expiresAt->getTimestamp()]);
    }
}
