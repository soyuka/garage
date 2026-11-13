<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CatalogLanguageTest extends AppTestCase
{
    public function testPartCategoriesAnswerInTheCallersLanguage(): void
    {
        $client = static::createClient();

        $english = $client->request('GET', '/cars/19107/part-categories?carType=PC', ['headers' => ['Accept-Language' => 'en-GB,en;q=0.9']]);
        $this->assertResponseIsSuccessful();
        $this->assertContains('Filters > Oil Filter', array_column($english->toArray()['hydra:member'], 'path'));

        $french = $client->request('GET', '/cars/19107/part-categories?carType=PC', ['headers' => ['Accept-Language' => 'fr-FR,fr;q=0.9']]);
        $this->assertContains('Filtre > Filtre à huile', array_column($french->toArray()['hydra:member'], 'path'));
    }

    public function testEnglishIsTheDefaultAndAnUnsupportedLanguageFoldsOntoIt(): void
    {
        $client = static::createClientWithoutLanguage();

        foreach ([[], ['Accept-Language' => 'de-DE,de;q=0.9']] as $headers) {
            $response = $client->request('GET', '/cars/19107/part-categories?carType=PC', ['headers' => $headers]);
            $this->assertContains('Filters > Oil Filter', array_column($response->toArray()['hydra:member'], 'path'));
        }
    }

    public function testFrenchIsStillServedWhenAsked(): void
    {
        $response = static::createClientWithoutLanguage()->request('GET', '/cars/19107/part-categories?carType=PC', ['headers' => ['Accept-Language' => 'fr']]);

        $this->assertContains('Filtre > Filtre à huile', array_column($response->toArray()['hydra:member'], 'path'));
    }

    public function testArticlesCarryTheEnglishProductGroup(): void
    {
        $client = static::createClient();

        $response = $client->request('GET', '/cars/19107/part-categories/100259/articles?carType=PC', ['headers' => ['Accept-Language' => 'en']]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['Oil Filter'], array_values(array_unique(array_column($response->toArray()['hydra:member'], 'productGroup'))));
    }

    public function testTheResponseVariesOnAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes');

        $this->assertStringContainsString('Accept-Language', implode(',', $client->getResponse()->getHeaders()['vary'] ?? []));
    }
}
