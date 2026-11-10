<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Agent\Ui\RenderIntentTools;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Mcp\Agent\McpToolbox;
use App\Tests\Mock\RecordingHub;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class McpDocumentationTest extends AppTestCase
{
    use McpClientTrait;
    use PilotFrameTrait;

    /**
     * @return array{Client, string, string}
     */
    private function seedManual(?string $pdf = null): array
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.(string) $vehicle->getId();

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
        $names = array_column($this->listTools($client, $sessionId), 'name');

        $this->assertContains('list_documents', $names);
        $this->assertContains('search_documentation', $names);
    }

    public function testSearchReturnsThePageWithItsRenaultLabelAndIsAccentInsensitive(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'CAPACITE huile'],
        ], 3));

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['hits']);
        $hit = $result['hits'][0];
        $this->assertSame('MR 361', $hit['title']);
        $this->assertSame('manual', $hit['kind']);
        $this->assertSame('MR 361 — Transmission', $hit['sourceRef']);
        $this->assertSame(2, $hit['page']);
        $this->assertSame('21A-6', $hit['pageLabel']);
        $this->assertStringContainsString('Capacité en huile', $hit['snippet']);
        $this->assertStringEndsWith('/file', $hit['url']);
        $this->assertArrayHasKey('documentId', $hit);
    }

    public function testSearchLinksEachHitDocumentAsAResource(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $response = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'huile'],
        ], 3);
        $documentId = $this->outcome($response)['hits'][0]['documentId'];

        $links = array_values(array_filter($response['result']['content'], static fn (array $c): bool => 'resource_link' === $c['type']));
        $this->assertSame('text', $response['result']['content'][0]['type']);
        $this->assertCount(1, $links, 'one link per document hit, not per page');
        $this->assertMatchesRegularExpression('#^https?://[^/]+'.preg_quote($iri, '#').'/documents/'.$documentId.'/file$#', $links[0]['uri']);
        $this->assertSame('mr361.pdf', $links[0]['name']);
        $this->assertSame('MR 361', $links[0]['title']);
        $this->assertSame('application/pdf', $links[0]['mimeType']);
        $this->assertGreaterThan(0, $links[0]['size']);
    }

    public function testNoMatchCarriesNoResourceLink(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $response = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'zzzzqqq'],
        ], 3);

        $this->assertSame(['text'], array_column($response['result']['content'], 'type'));
    }

    public function testSearchRanksByTermsMatched(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'couple vidange boite'],
        ], 3));

        $this->assertSame(1, $result['hits'][0]['page']);
        $this->assertSame('21A-5', $result['hits'][0]['pageLabel']);
    }

    public function testPhraseAndRareTermsOutrankStopwordNoise(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual(PdfFixture::rankingManual());

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'vis de volant couple'],
        ], 3));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['hits'][0]['page']);
        $this->assertStringContainsString('Vis de volant', $result['hits'][0]['snippet']);
    }

    public function testStopwordOnlyQueryIsNoMatch(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual(PdfFixture::rankingManual());

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'de la'],
        ], 3));

        $this->assertSame('no_match', $result['status']);
    }

    public function testMissIsNoMatchNotAnError(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'turbocompresseur'],
        ], 3));

        $this->assertSame('no_match', $result['status']);

        $kind = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'huile', 'kind' => 'quote'],
        ], 4));
        $this->assertSame('no_match', $kind['status']);
    }

    public function testSearchPublishesADocumentScanFrameWithTheRankedHits(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'huile'],
        ], 3));

        $frame = $this->lastPilotFrame();
        $this->assertNotNull($frame);
        $this->assertSame('documentScan', $frame['type']);
        $this->assertSame($iri, $frame['vehicle']);
        $this->assertSame('huile', $frame['query']);
        $this->assertSame(array_map(static fn (array $h): array => [$h['documentId'], $h['page']], $result['hits']), array_map(static fn (array $h): array => [$h['documentId'], $h['page']], $frame['hits']));
        $documentId = $result['hits'][0]['documentId'];
        $this->assertSame([
            'documentId' => $documentId,
            'page' => $result['hits'][0]['page'],
            'pageLabel' => $result['hits'][0]['pageLabel'],
            'url' => $iri.'/documents/'.$documentId.'/file',
            'title' => 'MR 361',
            'contentType' => 'application/pdf',
            'filename' => 'mr361.pdf',
            'pagesUrl' => $iri.'/documents/'.$documentId.'/pages',
            'snippet' => $result['hits'][0]['snippet'],
            'sourceRef' => 'MR 361 — Transmission',
        ], $frame['hits'][0]);
    }

    public function testANoMatchSearchStillPublishesTheScanWithNoHits(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'turbocompresseur'],
        ], 3);

        $this->assertSame(['type' => 'documentScan', 'vehicle' => $iri, 'query' => 'turbocompresseur', 'hits' => []], $this->lastPilotFrame());
    }

    public function testTheInAppAgentScanGoesToTheTurnSession(): void
    {
        [, , $iri] = $this->seedManual();
        static::getContainer()->get(RenderIntentTools::class)->bindSession('https://localhost/agent/sessions/abc');

        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('1', 'search_documentation', ['vehicle' => $iri, 'query' => 'huile']));

        $hub = static::getContainer()->get(RecordingHub::class);
        $update = $hub->updates[array_key_last($hub->updates)];
        $this->assertSame(['https://localhost/agent/sessions/abc'], $update->getTopics());
        $this->assertSame('documentScan', json_decode($update->getData(), true)['type']);
    }

    public function testAnInvalidSearchPublishesNothing(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => '   '],
        ], 3);

        $this->assertNull($this->lastPilotFrame());
    }

    public function testListDocuments(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_documents',
            'arguments' => ['vehicle' => $iri],
        ], 3));

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['documents']);
        $this->assertSame(2, $result['documents'][0]['pageCount']);
        $this->assertSame('MR 361 — Transmission', $result['documents'][0]['sourceRef']);
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        [$client, $sessionId] = $this->seedManual();

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_documents',
            'arguments' => ['vehicle' => '/garage/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        ], 3));

        $this->assertSame('not_found', $result['error']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function read(Client $client, string $sessionId, array $arguments): array
    {
        return $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'read_documentation_page', 'arguments' => $arguments], 9));
    }

    private function documentId(Client $client, string $sessionId, string $iri): string
    {
        $listed = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'list_documents', 'arguments' => ['vehicle' => $iri]], 8));

        return $listed['documents'][0]['id'];
    }

    public function testReadDocumentationPageIsAdvertisedWithItsArguments(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $byName = array_column($this->listTools($client, $sessionId), null, 'name');

        $this->assertArrayHasKey('read_documentation_page', $byName);
        $this->assertEqualsCanonicalizing(['vehicle', 'document', 'page'], array_keys($byName['read_documentation_page']['inputSchema']['properties']));
    }

    public function testReadDocumentationPageReturnsTheFullPageText(): void
    {
        $filler = array_fill(0, 8, 'Rappel de securite avant intervention sur la boite.');
        $pdf = PdfFixture::build([
            ['Page une'],
            ['Boite JH3', ...$filler, 'Couple de serrage vis demarreur 44 Nm', '21A-9'],
        ]);
        [$client, $sessionId, $iri] = $this->seedManual($pdf);
        $documentId = $this->documentId($client, $sessionId, $iri);

        $result = $this->read($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId, 'page' => 2]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($documentId, $result['documentId']);
        $this->assertSame('MR 361', $result['title']);
        $this->assertSame('manual', $result['kind']);
        $this->assertSame('MR 361 — Transmission', $result['sourceRef']);
        $this->assertSame(2, $result['page']);
        $this->assertSame('21A-9', $result['pageLabel']);
        $this->assertSame(2, $result['pageCount']);
        $this->assertGreaterThan(300, mb_strpos($result['text'], 'Couple de serrage vis demarreur 44 Nm'));
        $this->assertStringEndsWith('/documents/'.$documentId.'/file', $result['url']);
    }

    public function testReadDocumentationPageRejectsAPageOutOfRange(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();
        $documentId = $this->documentId($client, $sessionId, $iri);

        foreach ([0, 3] as $page) {
            $result = $this->read($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId, 'page' => $page]);
            $this->assertSame('invalid_input', $result['error']);
            $this->assertStringContainsString('between 1 and 2', $result['message']);
        }
    }

    public function testReadDocumentationPageRequiresItsArguments(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();
        $documentId = $this->documentId($client, $sessionId, $iri);

        $this->assertSame('invalid_input', $this->read($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId])['error']);
        $this->assertSame('invalid_input', $this->read($client, $sessionId, ['vehicle' => $iri, 'page' => 1])['error']);
        $this->assertSame('invalid_input', $this->read($client, $sessionId, ['document' => $documentId, 'page' => 1])['error']);
    }

    public function testReadDocumentationPageUnknownDocumentIsNotFound(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->read($client, $sessionId, ['vehicle' => $iri, 'document' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'page' => 1]);

        $this->assertSame('not_found', $result['error']);
    }

    public function testReadDocumentationPageOfAnotherOwnersVehicleIsNotFound(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();
        $documentId = $this->documentId($client, $sessionId, $iri);
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'ESPACE', VehicleType::Car, 'user', year: 2010);
        $vehicle->setOwner('someone-else@example.com');
        $dm->persist($vehicle);
        $dm->flush();

        $result = $this->read($client, $sessionId, ['vehicle' => '/garage/vehicles/'.(string) $vehicle->getId(), 'document' => $documentId, 'page' => 1]);

        $this->assertSame('not_found', $result['error']);
    }

    public function testSearchingAnotherOwnersVehicleIsNotFound(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();
        $this->handOver($iri, 'someone-else@example.com');

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'search_documentation',
            'arguments' => ['vehicle' => $iri, 'query' => 'huile'],
        ], 3));

        $this->assertSame('not_found', $result['error'] ?? null);
    }

    public function testListingAnotherOwnersDocumentsIsNotFound(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();
        $this->handOver($iri, 'someone-else@example.com');

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_documents',
            'arguments' => ['vehicle' => $iri],
        ], 3));

        $this->assertSame('not_found', $result['error'] ?? null);
    }

    private function handOver(string $iri, string $owner): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->createQueryBuilder(Vehicle::class)->updateOne()
            ->field('id')->equals(substr($iri, (int) strrpos($iri, '/') + 1))
            ->field('owner')->set($owner)
            ->getQuery()->execute();
        $dm->clear();
    }
}
