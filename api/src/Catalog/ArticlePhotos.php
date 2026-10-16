<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Article;
use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use App\PartsApi\CarType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The catalogue photo of an article, proxied: the browser never talks to the image host, and the
 * only URL ever fetched is one a getArticleMedia response gave for that very article. The article
 * must sit in a listing the server already cached (one it has shown), so a request can never make
 * the server spend the media quota on an arbitrary id. Bytes are cached forever, keyed by URL.
 */
final class ArticlePhotos
{
    private const int MAX_BYTES = 2_000_000;
    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly PartsCatalog $catalog,
        private readonly ArticlePhotoStore $store,
        #[Autowire(service: 'article_photos.http_client')]
        private readonly HttpClientInterface $images,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function photo(int $carId, CarType $carType, int $categoryId, int $artId): ?ArticlePhoto
    {
        $listed = $this->catalog->cachedArticles($carId, $categoryId, $carType);
        if (null === $listed || !\in_array($artId, array_map(static fn (Article $a): int => $a->id, $listed), true)) {
            return null;
        }

        try {
            $media = $this->catalog->articleMedia($artId);
        } catch (UpstreamException|MissingApiKeyException $e) {
            $this->logger->warning('Article media unavailable for {artId}: {reason}', ['artId' => $artId, 'reason' => $e->getMessage()]);

            return null;
        }

        foreach ($media as $item) {
            if ('https' === parse_url($item->source, \PHP_URL_SCHEME) && 'PDF' !== $item->type) {
                return $this->download($item->source);
            }
        }

        return null;
    }

    private function download(string $url): ?ArticlePhoto
    {
        $cached = $this->store->find($url);
        if (null !== $cached) {
            return $cached;
        }

        try {
            $body = $this->images->request('GET', $url, [
                'timeout' => 10,
                'max_redirects' => 2,
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('Catalogue photo over the size cap.');
                    }
                },
            ])->getContent();
        } catch (ExceptionInterface|\RuntimeException $e) {
            $this->logger->warning('Catalogue photo fetch failed for {url}: {reason}', ['url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }

        $type = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($body);
        if (!\in_array($type, self::IMAGE_TYPES, true)) {
            $this->logger->warning('Catalogue photo at {url} is not an image ({type})', ['url' => $url, 'type' => $type]);

            return null;
        }

        $photo = new ArticlePhoto($type, $body);
        $this->store->save($url, $photo);

        return $photo;
    }
}
