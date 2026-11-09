<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpClient\Exception\TransportException;

final class McpServerTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/list', [], 2);

        $tools = array_column($result['result']['tools'], 'name');
        foreach (['lookup_plate', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles'] as $tool) {
            $this->assertContains($tool, $tools);
        }
        $this->assertNotContains('get_fill_volumes', $tools);
    }

    /**
     * Server-wide invariant for Claude connector-directory submission: every tool must carry a
     * human-readable title and a readOnlyHint. Guards future tools too — adding one without
     * annotations breaks this. Behaviour-specific hints (destructive/openWorld) checked inline.
     */
    public function testEveryToolCarriesTitleAndReadOnlyHint(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->listTools($client, $sessionId) as $tool) {
            $tools[$tool['name']] = $tool;
        }

        foreach ($tools as $name => $tool) {
            $this->assertNotEmpty($tool['title'] ?? null, "{$name} must carry a title");
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations'] ?? [], "{$name} must declare readOnlyHint");
        }

        // lookup_plate queries an external registry (proxied through bonneref) → open world.
        $this->assertTrue($tools['lookup_plate']['annotations']['openWorldHint'] ?? null);
        // plan_maintenance writes but is additive: it upserts the open task and never deletes.
        $this->assertFalse($tools['plan_maintenance']['annotations']['readOnlyHint'] ?? null);
        $this->assertFalse($tools['plan_maintenance']['annotations']['destructiveHint'] ?? null);
    }

    public function testLookupPlateTool(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'XC-302-ZC'],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('IVECO', $content['make']);
        $this->assertSame(34247, $content['carId']);
        $this->assertSame('CV', $content['carType']);
    }

    /**
     * "Servers MUST provide structured results that conform to [the outputSchema]" (MCP 2026-07-28).
     * Pins which tools advertise one, so a new one cannot ship without a sample checked here.
     */
    public function testASuccessfulResultConformsToTheAdvertisedOutputSchema(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $schemas = [];
        foreach ($this->listTools($client, $sessionId) as $tool) {
            if (isset($tool['outputSchema'])) {
                $schemas[$tool['name']] = $tool['outputSchema'];
            }
        }
        $this->assertSame(['lookup_plate'], array_keys($schemas));

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'XC-302-ZC'],
        ], 3)['result'];

        $this->assertFalse($result['isError']);
        $validation = (new Validator())->validate(
            json_decode(json_encode($result['structuredContent'], \JSON_THROW_ON_ERROR)),
            json_encode($schemas['lookup_plate'], \JSON_THROW_ON_ERROR),
        );
        $error = $validation->error();
        $this->assertNull($error, null === $error ? '' : json_encode((new ErrorFormatter())->format($error), \JSON_PRETTY_PRINT));
    }

    /**
     * An unknown plate is a definitive miss: a business-logic error (isError) the model must not
     * retry, told apart from a stall by its status token.
     */
    public function testUnknownPlateSurfacesAsANotFoundError(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'ZZ-999-ZZ'],
        ], 6));

        $this->assertSame('not_found', $result['error']);
        $this->assertStringContainsString('definitive miss', $result['message']);
    }

    /**
     * The 2026-09-14 regression: the registry stalled, the model was handed an opaque error, and it
     * told the user the plate did not exist. The model must now be able to tell the two apart.
     */
    public function testARegistryTimeoutSurfacesAsRetryableAndIsNotConfusedWithAnUnknownPlate(): void
    {
        $client = static::createClient();
        static::bonneref()->route('/vehicles/TT000TT', new TransportException('Idle timeout reached'));
        $sessionId = $this->initialize($client);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'TT-000-TT'],
        ], 7));

        $this->assertSame('upstream_unavailable', $result['error']);
        $this->assertStringContainsString('retry', $result['message'], 'the whole point: a stall is retryable, an unknown plate is not');
        $this->assertStringContainsString('never report it to the user as "not found"', $result['message']);
    }

    /**
     * bonneref answers 501 when its plate registry has no token. mpa cannot see bonneref's env, so the
     * answer itself is the signal: a clean, final "not configured", never a retryable outage — and
     * nothing of bonneref's problem detail is forwarded.
     */
    public function testAPlateLookupTheCatalogueServerHasNotConfiguredSaysSo(): void
    {
        $client = static::createClient();
        static::bonneref()->route('/vehicles/NC000NC', '{"title":"An error occurred","status":501,"detail":"env API_PLAQUE_TOKEN"}', 501);
        $sessionId = $this->initialize($client);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'NC-000-NC'],
        ], 8));

        $this->assertSame('not_configured', $result['error']);
        $this->assertStringStartsWith('plate lookup is not configured on the catalogue server', $result['message']);
        $this->assertStringContainsString('do not retry', $result['message']);
        $this->assertStringNotContainsString('API_PLAQUE_TOKEN', $result['message']);
    }
}
