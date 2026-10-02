<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class McpDocumentationTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @return array{Client, string, string}
     */
    private function seedManual(?string $pdf = null): array
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $path = tempnam(sys_get_temp_dir(), 'mpa_doc_');
        file_put_contents($path, $pdf ?? PdfFixture::renaultManual());
        $browser = $client->getKernelBrowser();
        $browser->request('POST', $iri.'/documents', ['kind' => 'manual', 'title' => 'MR 361', 'sourceRef' => 'MR 361 — Transmission'], [
            'file' => new UploadedFile($path, 'mr361.pdf', 'application/pdf', test: true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for('mcp-user', self::TEST_OWNER)]);
        self::assertSame(201, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return [$client, $this->initialize($client), $iri];
    }

    public function testToolsAreAdvertised(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $names = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        self::assertContains('list_documents', $names);
        self::assertContains('search_documentation', $names);
    }

    public function testSearchReturnsThePageWithItsRenaultLabelAndIsAccentInsensitive(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'CAPACITE huile'],
        ], 3)['result']['structuredContent'];

        self::assertSame('ok', $result['status']);
        self::assertCount(1, $result['hits']);
        $hit = $result['hits'][0];
        self::assertSame('MR 361', $hit['title']);
        self::assertSame('manual', $hit['kind']);
        self::assertSame('MR 361 — Transmission', $hit['sourceRef']);
        self::assertSame(2, $hit['page']);
        self::assertSame('21A-6', $hit['pageLabel']);
        self::assertStringContainsString('Capacité en huile', $hit['snippet']);
        self::assertStringEndsWith('/file', $hit['url']);
        self::assertArrayHasKey('documentId', $hit);
    }

    public function testSearchRanksByTermsMatched(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'couple vidange boite'],
        ], 3)['result']['structuredContent'];

        self::assertSame(1, $result['hits'][0]['page']);
        self::assertSame('21A-5', $result['hits'][0]['pageLabel']);
    }

    public function testPhraseAndRareTermsOutrankStopwordNoise(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual(PdfFixture::rankingManual());

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'vis de volant couple'],
        ], 3)['result']['structuredContent'];

        self::assertSame('ok', $result['status']);
        self::assertSame(3, $result['hits'][0]['page']);
        self::assertStringContainsString('Vis de volant', $result['hits'][0]['snippet']);
    }

    public function testStopwordOnlyQueryIsNoMatch(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual(PdfFixture::rankingManual());

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'de la'],
        ], 3)['result']['structuredContent'];

        self::assertSame('no_match', $result['status']);
    }

    public function testMissIsNoMatchNotAnError(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'turbocompresseur'],
        ], 3)['result']['structuredContent'];

        self::assertSame('no_match', $result['status']);

        $kind = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'huile', 'kind' => 'quote'],
        ], 4)['result']['structuredContent'];
        self::assertSame('no_match', $kind['status']);
    }

    public function testListDocuments(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_documents',
            'arguments' => ['vehicle' => $iri],
        ], 3)['result']['structuredContent'];

        self::assertSame('ok', $result['status']);
        self::assertCount(1, $result['documents']);
        self::assertSame(2, $result['documents'][0]['pageCount']);
        self::assertSame('MR 361 — Transmission', $result['documents'][0]['sourceRef']);
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        [$client, $sessionId] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_documents',
            'arguments' => ['vehicle' => '/garage/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        ], 3)['result']['structuredContent'];

        self::assertSame('not_found', $result['status']);
    }
}
