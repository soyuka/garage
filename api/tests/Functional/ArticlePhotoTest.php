<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PartsApi\PartsApiClient;

final class ArticlePhotoTest extends AppTestCase
{
    private const LISTING = '/cars/16182/part-categories/100198/narrowed?productGroupId=51';
    private const PHOTO = '/cars/16182/part-categories/100198/articles/%d/photo';
    private const IMAGE = __DIR__.'/../Fixtures/media/images/30/94cfd50fae0aee8544b3f9c8c87cd6db34239af3.webp';

    public function testServesTheFirstCataloguePhotoOfAListedArticle(): void
    {
        $client = static::createClient();
        $client->request('GET', self::LISTING);

        $response = $client->request('GET', sprintf(self::PHOTO, 112767557));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/webp');
        $this->assertResponseHeaderSame('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('immutable', (string) $response->getHeaders()['cache-control'][0]);
        $this->assertSame(file_get_contents(self::IMAGE), $response->getContent());
        $this->assertSame(1, $this->upstreamCalls('getArticleMedia'));
    }

    public function testThePhotoIsFetchedOnceThenServedFromCache(): void
    {
        $client = static::createClient();
        $client->request('GET', self::LISTING);
        $client->request('GET', sprintf(self::PHOTO, 112767557));

        $client->request('GET', sprintf(self::PHOTO, 112767557));

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->upstreamCalls('getArticleMedia'));
    }

    public function testAnArticleOutsideTheCachedListingSpendsNothing(): void
    {
        $client = static::createClient();
        $client->request('GET', self::LISTING);

        $client->request('GET', sprintf(self::PHOTO, 9674372));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(0, $this->upstreamCalls('getArticleMedia'));
    }

    public function testAnUncachedListingNeverTriggersACatalogueCall(): void
    {
        static::createClient()->request('GET', sprintf(self::PHOTO, 112767557));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(0, static::getContainer()->get(PartsApiClient::class)->upstreamCalls);
    }

    public function testAnArticleWithoutMediaIsA404AndTheEmptyAnswerIsKept(): void
    {
        $client = static::createClient();
        $client->request('GET', self::LISTING);
        $client->request('GET', sprintf(self::PHOTO, 2841562));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(1, $this->upstreamCalls('getArticleMedia'));

        $client->request('GET', sprintf(self::PHOTO, 2841562));
        $this->assertSame(0, $this->upstreamCalls('getArticleMedia'));
    }

    private function upstreamCalls(string $method): int
    {
        return static::getContainer()->get(PartsApiClient::class)->upstreamCallsByMethod[$method] ?? 0;
    }
}
