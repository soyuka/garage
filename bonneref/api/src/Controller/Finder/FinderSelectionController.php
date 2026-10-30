<?php

declare(strict_types=1);

namespace App\Controller\Finder;

use App\Entity\FinderSelection;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /agent/selections/{id}: the agent's retained variant behind a finder share link. Anonymous on
 * purpose (« Copier le lien » is meant to be opened by someone else): the id is an unguessable ULID
 * and the answer carries only what the link's page already shows, never the owner, plate or turn.
 */
final readonly class FinderSelectionController
{
    public function __construct(private DocumentManager $documentManager)
    {
    }

    #[Route('/agent/selections/{id}', name: 'finder_selection', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $selection = $this->documentManager->find(FinderSelection::class, $id) ?? throw new NotFoundHttpException('Unknown selection.');

        return new JsonResponse($selection->publicView(), headers: ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
