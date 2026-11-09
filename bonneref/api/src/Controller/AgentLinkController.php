<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Ui\TurnSessionTopic;
use App\Security\OwnerContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the page the Mercure topic its turns stream on, `?session=<tab id>`: a digest of (owner, id)
 * the browser cannot compute ({@see TurnSessionTopic}).
 */
final readonly class AgentLinkController
{
    public function __construct(
        private TurnSessionTopic $sessions,
        private OwnerContext $owner,
    ) {
    }

    #[Route('/agent/link', name: 'agent_link', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = (string) $request->query->get('session', '');
        if (!TurnSessionTopic::isValidId($session)) {
            return new JsonResponse(['error' => 'session must be 1 to 64 letters, digits, "-" or "_"'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['sessionTopic' => $this->sessions->topic($this->owner->require(), $session)]);
    }
}
