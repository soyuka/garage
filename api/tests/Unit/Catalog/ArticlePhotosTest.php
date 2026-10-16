<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\ArticlePhotos;
use App\Catalog\PartsCatalog;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use App\Tests\Support\InMemoryArticlePhotoStore;
use App\Tests\Support\InMemoryPartsApiResponseStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArticlePhotosTest extends TestCase
{
    private const int ART_ID = 112767557;

    /** @var list<string> */
    private array $imageRequests = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeSources(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/images/x.webp'];
        yield 'private network' => ['https://10.0.0.5/images/x.webp'];
        yield 'link-local metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'plain http' => ['http://img.example.com/x.webp'];
        yield 'file scheme' => ['file:///etc/passwd'];
    }

    #[DataProvider('unsafeSources')]
    public function testNeverFetchesAMediaSourceOutsideThePublicHttpsWeb(string $source): void
    {
        $this->assertNull($this->photos($source)->photo(16182, CarType::PassengerCar, 100198, self::ART_ID));
        $this->assertSame([], $this->imageRequests);
    }

    public function testFetchesAPublicHttpsSource(): void
    {
        $this->photos('https://93.184.215.14/images/x.webp')->photo(16182, CarType::PassengerCar, 100198, self::ART_ID);

        $this->assertSame(['https://93.184.215.14/images/x.webp'], $this->imageRequests);
    }

    private function photos(string $source): ArticlePhotos
    {
        $partsApi = new MockHttpClient(static function (string $method, string $url) use ($source): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return new MockResponse(json_encode(match ($query['method'] ?? '') {
                'getArticles' => [['ART_ID' => self::ART_ID, 'ART_ARTICLE_NR' => '0 986 141 133', 'ART_SUP_BRAND' => 'BOSCH', 'PRODUCT GROUP' => 'Rotule axiale', 'PT_ID' => 51, 'SUP_ID' => 30]],
                'getArticleMedia' => [['ART_MEDIA_DESCRIPTION' => 'Illustration', 'ART_MEDIA_SOURCE' => $source, 'ART_MEDIA_SUP_ID' => 30, 'ART_MEDIA_TYPE' => 'JPG']],
                default => [],
            }, \JSON_THROW_ON_ERROR));
        });
        $catalog = new PartsCatalog(new PartsApiClient($partsApi, ['getArticles' => 'k', 'getArticleMedia' => 'k'], new InMemoryPartsApiResponseStore()));
        $catalog->articles(16182, 100198, CarType::PassengerCar);

        $images = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->imageRequests[] = $url;

            return new MockResponse('RIFF....WEBPVP8 ', ['http_code' => 200]);
        });

        return new ArticlePhotos($catalog, new InMemoryArticlePhotoStore(), new NoPrivateNetworkHttpClient($images), new NullLogger());
    }
}
