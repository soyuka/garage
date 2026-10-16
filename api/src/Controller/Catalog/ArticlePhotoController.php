<?php

declare(strict_types=1);

namespace App\Controller\Catalog;

use App\Catalog\ArticlePhotos;
use App\PartsApi\CarType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /cars/{carId}/part-categories/{categoryId}/articles/{artId}/photo — the catalogue photo of an
 * article the finder shows without a 3D model. A plain controller because the answer is image bytes.
 * The route takes no URL by design: see {@see ArticlePhotos}.
 */
final class ArticlePhotoController
{
    public function __construct(private readonly ArticlePhotos $photos)
    {
    }

    #[Route('/cars/{carId}/part-categories/{categoryId}/articles/{artId}/photo', name: 'article_photo', requirements: ['carId' => '\d+', 'categoryId' => '\d+', 'artId' => '\d+'], methods: ['GET'])]
    public function __invoke(int $carId, int $categoryId, int $artId, #[MapQueryParameter] string $carType = 'PC'): Response
    {
        $type = CarType::tryFrom($carType) ?? throw new NotFoundHttpException('Unknown carType.');
        $photo = $this->photos->photo($carId, $type, $categoryId, $artId) ?? throw new NotFoundHttpException('No catalogue photo for this article.');

        return new Response($photo->body, Response::HTTP_OK, [
            'Content-Type' => $photo->contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
