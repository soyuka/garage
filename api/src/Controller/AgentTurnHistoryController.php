<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AgentTurnRepository;
use App\Repository\UserRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Curates the finder home history without touching the journal: clearing moves the owner's
 * `historyClearedAt` cursor, hiding flags one row. Both only change what `GET /agent/turns?history=1`
 * returns; the journal drawer keeps every row. Under the `^/agent/turn` firewall, so always authenticated.
 */
final class AgentTurnHistoryController
{
    public function __construct(
        private readonly OwnerContext $owner,
        private readonly UserRepository $users,
        private readonly AgentTurnRepository $turns,
        private readonly DocumentManager $dm,
    ) {
    }

    /**
     * Without a body, hides everything up to now. `{"restore": <ISO date|null>}` sets the cursor
     * back to that value instead: the undo of a clear, `previous` being what the clear replaced.
     */
    #[Route('/agent/turns/history/clear', name: 'agent_turn_history_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $owner = $this->owner->require();
        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = \is_array($payload) ? $payload : [];

        $target = new \DateTimeImmutable();
        if (\array_key_exists('restore', $payload)) {
            $restore = $payload['restore'];
            if (null === $restore) {
                $target = null;
            } else {
                try {
                    $target = \is_string($restore) ? new \DateTimeImmutable($restore) : throw new \InvalidArgumentException();
                } catch (\Exception) {
                    return new JsonResponse(['error' => 'restore must be an ISO 8601 date or null'], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $user = $this->users->findOneByExternalId($owner);
        if (null === $user) {
            $user = new User($owner);
            $this->dm->persist($user);
        }
        $previous = $user->getHistoryClearedAt();
        $user->setHistoryClearedAt($target);
        $this->dm->flush();

        return new JsonResponse([
            'clearedAt' => $target?->format(\DateTimeInterface::ATOM),
            'previous' => $previous?->format(\DateTimeInterface::ATOM),
        ]);
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
