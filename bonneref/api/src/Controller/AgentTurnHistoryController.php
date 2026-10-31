<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AgentTurnRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Curates the finder home history without touching the journal: hiding flags one row, which only
 * changes what `GET /agent/turns?history=1` returns; the journal drawer keeps every row. Under the `^/agent/turn` firewall, so always authenticated.
 */
final readonly class AgentTurnHistoryController
{
    public function __construct(
        private OwnerContext $owner,
        private AgentTurnRepository $turns,
        private DocumentManager $dm,
    ) {
    }

    #[Route('/agent/turns/{id}/hide', name: 'agent_turn_hide', requirements: ['id' => '[0-9A-Za-z]{26}'], methods: ['POST'])]
    public function hide(string $id): Response
    {
        $turn = Ulid::isValid($id) ? $this->turns->findOneForOwner(Ulid::fromString($id), $this->owner->require()) : null;
        if (null === $turn) {
            return new JsonResponse(['error' => 'not found'], Response::HTTP_NOT_FOUND);
        }

        $turn->hideFromHistory();
        $this->dm->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
