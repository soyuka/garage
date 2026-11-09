<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class McpServerTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/list', [], 2);

        $tools = array_column($result['result']['tools'], 'name');
        foreach (['lookup_plate', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles'] as $tool) {
            $this->assertContains($tool, $tools);
        }
        $this->assertEqualsCanonicalizing(['lookup_plate', 'decode_vin', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles', 'narrow_parts', 'decode_build_code', 'compare_brands', 'get_service_parts'], $tools);
    }

    public function testNoToolSpeaksOfTheGarageOrItsTools(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $this->assertDoesNotMatchRegularExpression('/garage|propose_actions|the task/i', $tool['description'] ?? '', $tool['name']);
        }
    }

    /**
     * Server-wide invariant for Claude connector-directory submission: every tool must carry a
     * human-readable title and a readOnlyHint. Guards future tools too — adding one without
     * annotations breaks this. Behaviour-specific hints (destructive/openWorld) checked inline.
     */
    public function testEveryToolCarriesTitleAndReadOnlyHint(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        foreach ($tools as $name => $tool) {
            $this->assertNotEmpty($tool['title'] ?? null, "{$name} must carry a title");
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations'] ?? [], "{$name} must declare readOnlyHint");
        }

        // lookup_plate queries an external registry (SIV) → open world.
        $this->assertTrue($tools['lookup_plate']['annotations']['openWorldHint'] ?? null);
        // The catalogue server is read-only end to end.
        foreach ($tools as $name => $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], "{$name} must be read-only");
        }
    }

    public function testLookupPlateTool(): void
    {
        $client = static::createClient();
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
     * Without the SIV token, lookup_plate is not advertised at all (CapabilityGatedToolsTest), but the
     * HTTP plate route stays and answers 501: mon-parc-auto cannot read this server's env, so that
     * status is how it learns "plate lookup is not configured on the catalogue server".
     */
    public function testMissingApiKeyAnswers501OverHttp(): void
    {
        $previous = $_SERVER['API_PLAQUE_TOKEN'] ?? '';
        $_SERVER['API_PLAQUE_TOKEN'] = $_ENV['API_PLAQUE_TOKEN'] = '';

        try {
            $client = static::createClient();
            $response = $client->request('GET', '/vehicles/AB123CD', ['headers' => ['Accept' => 'application/ld+json']]);

            $this->assertResponseStatusCodeSame(501);
            $this->assertStringNotContainsString('API_PLAQUE_TOKEN', $response->getContent(false));
        } finally {
            $_SERVER['API_PLAQUE_TOKEN'] = $_ENV['API_PLAQUE_TOKEN'] = $previous;
        }
    }

    /**
     * "Servers MUST provide structured results that conform to [the outputSchema]" (MCP 2026-07-28).
     * Pins which tools advertise one, so a new one cannot ship without a sample checked here.
     */
    public function testASuccessfulResultConformsToTheAdvertisedOutputSchema(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $schemas = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
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
        $sessionId = $this->initialize($client);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'ZZ-999-ZZ'],
        ], 6));

        $this->assertSame('not_found', $result['error']);
        $this->assertStringContainsString('definitive miss', $result['message']);
    }

    /**
     * The 2026-09-14 regression: the SIV stalled, the model was handed an opaque error, and it
     * told the user the plate did not exist. The model must now be able to tell the two apart.
     */
    public function testSivTimeoutSurfacesAsRetryableAndIsNotConfusedWithAnUnknownPlate(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'TT-000-TT'],
        ], 7));

        $this->assertSame('upstream_unavailable', $result['error']);
        $this->assertStringContainsString('retry', $result['message'], 'the whole point: a stall is retryable, an unknown plate is not');
        $this->assertStringContainsString('never report it to the user as "not found"', $result['message']);
    }
}
