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

final class McpShowDocumentTest extends AppTestCase
{
    use McpClientTrait;
    use PilotFrameTrait;

    private const string OTHER_OWNER = 'someone-else@example.com';

    /**
     * @return array{Client, string, string, string}
     */
    private function seedManual(string $owner = self::TEST_OWNER): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner($owner);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $path = tempnam(sys_get_temp_dir(), 'mpa_doc_');
        file_put_contents($path, PdfFixture::renaultManual());
        $browser = $client->getKernelBrowser();
        $browser->request('POST', $iri.'/documents', ['kind' => 'manual', 'title' => 'MR 361', 'sourceRef' => 'MR 361 — Transmission'], [
            'file' => new UploadedFile($path, 'mr361.pdf', 'application/pdf', test: true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for('mcp-user', $owner)]);
        self::assertSame(201, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $documentId = json_decode((string) $browser->getResponse()->getContent(), true)['id'];

        return [$client, $this->initialize($client), $iri, $documentId];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function show(Client $client, string $sessionId, array $arguments): array
    {
        return $this->call($client, $sessionId, 'tools/call', ['name' => 'show_document', 'arguments' => $arguments], 9)['result']['structuredContent'] ?? [];
    }

    public function testIsAdvertisedWithItsArguments(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $byName = array_column($tools, null, 'name');

        self::assertArrayHasKey('show_document', $byName);
        $properties = array_keys($byName['show_document']['inputSchema']['properties']);
        foreach (['vehicle', 'document', 'page', 'highlight'] as $property) {
            self::assertContains($property, $properties);
        }
    }

    public function testOpensTheDocumentAtAPageWithTheHighlight(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual();

        $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId, 'page' => 2, 'highlight' => 'capacité huile']);

        self::assertSame([
            'type' => 'showDocument',
            'url' => $iri.'/documents/'.$documentId.'/file',
            'title' => 'MR 361',
            'contentType' => 'application/pdf',
            'filename' => 'mr361.pdf',
            'page' => 2,
            'highlight' => 'capacité huile',
            'pagesUrl' => $iri.'/documents/'.$documentId.'/pages',
        ], $this->lastPilotFrame());
    }

    public function testResultReportsWhatWasRendered(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'show_document',
            'arguments' => ['vehicle' => $iri, 'document' => $documentId],
        ], 9)['result'];

        self::assertStringContainsString('rendered', json_encode($result));
        self::assertStringContainsString('showDocument', json_encode($result));
    }

    public function testPageAndHighlightAreAbsentWhenNotGiven(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual();

        $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId]);

        self::assertSame([
            'type' => 'showDocument',
            'url' => $iri.'/documents/'.$documentId.'/file',
            'title' => 'MR 361',
            'contentType' => 'application/pdf',
            'filename' => 'mr361.pdf',
            'pagesUrl' => $iri.'/documents/'.$documentId.'/pages',
        ], $this->lastPilotFrame());
    }

    public function testAPagePastTheEndIsRejectedAndNothingOpens(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual();

        $result = $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId, 'page' => 3]);

        self::assertSame('invalid_input', $result['status']);
        self::assertStringContainsString('between 1 and 2', $result['reason']);
        self::assertNull($this->lastPilotFrame());
    }

    public function testPageZeroIsRejected(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual();

        $result = $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId, 'page' => 0]);

        self::assertSame('invalid_input', $result['status']);
        self::assertNull($this->lastPilotFrame());
    }

    public function testAnUnknownDocumentIsNotFoundAndNothingOpens(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        $result = $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => 'nope']);

        self::assertSame('not_found', $result['status']);
        self::assertNull($this->lastPilotFrame());
    }

    public function testMissingArgumentsAreInvalidInput(): void
    {
        [$client, $sessionId, $iri] = $this->seedManual();

        self::assertSame('invalid_input', $this->show($client, $sessionId, ['vehicle' => $iri])['status']);
        self::assertNull($this->lastPilotFrame());
    }

    public function testADocumentOfAnotherOwnersVehicleIsNotFound(): void
    {
        [$client, $sessionId, $iri, $documentId] = $this->seedManual(self::OTHER_OWNER);

        $result = $this->show($client, $sessionId, ['vehicle' => $iri, 'document' => $documentId]);

        self::assertSame('not_found', $result['status']);
        self::assertNull($this->lastPilotFrame());
    }

    public function testTheInAppAgentSeesItAndItsFrameGoesToTheTurnSession(): void
    {
        [, , $iri, $documentId] = $this->seedManual();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        $names = array_map(static fn ($t): string => $t->getName(), $toolbox->getTools());
        self::assertContains('show_document', $names);

        static::getContainer()->get(RenderIntentTools::class)->bindSession('https://localhost/agent/sessions/abc');
        $toolbox->execute(new ToolCall('1', 'show_document', ['vehicle' => $iri, 'document' => $documentId, 'page' => 1]));

        $hub = static::getContainer()->get(RecordingHub::class);
        $update = $hub->updates[array_key_last($hub->updates)];
        self::assertSame(['https://localhost/agent/sessions/abc'], $update->getTopics());
        self::assertSame('showDocument', json_decode($update->getData(), true)['type']);
    }
}
