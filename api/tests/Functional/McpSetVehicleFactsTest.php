<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\PlannedMaintenance;
use App\Entity\VehicleFact;
use App\Mcp\Agent\McpToolbox;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Result\ToolCall;

final class McpSetVehicleFactsTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @return array{0: Client, 1: string, 2: string}
     */
    private function clientWithVehicle(): array
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $vehicle = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6', 'year' => 2007],
        ], 2))['vehicle'];

        $this->assertIsString($vehicle['@id']);

        return [$client, $sessionId, $vehicle['@id']];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(Client $client, string $sessionId, string $name, array $arguments, int $id): array
    {
        return $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => $name, 'arguments' => $arguments], $id));
    }

    public function testBothToolsAreDiscoverableWithGeneratedSchemas(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->listTools($client, $sessionId) as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('set_vehicle_facts', $tools);
        $this->assertArrayHasKey('remove_vehicle_facts', $tools);
        $this->assertFalse($tools['set_vehicle_facts']['annotations']['readOnlyHint'] ?? null);
        $this->assertSame(['vehicle', 'facts'], $tools['set_vehicle_facts']['inputSchema']['required']);
        $this->assertSame(['key', 'value', 'source'], $tools['set_vehicle_facts']['inputSchema']['properties']['facts']['items']['required']);
        $this->assertArrayHasKey('keys', $tools['remove_vehicle_facts']['inputSchema']['properties']);
        $this->assertStringContainsString('snake_case', $tools['set_vehicle_facts']['description']);
    }

    public function testPlanMaintenanceNoLongerAdvertisesAttributes(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach ($this->listTools($client, $sessionId) as $tool) {
            if ('plan_maintenance' === $tool['name']) {
                $this->assertArrayNotHasKey('attributes', $tool['inputSchema']['properties']);
                $this->assertStringNotContainsString('attributes', $tool['description']);

                return;
            }
        }
        $this->fail('plan_maintenance is not listed');
    }

    public function testBothToolsReachTheInAppAgent(): void
    {
        static::createClient();
        $names = array_map(
            static fn ($tool) => $tool->getName(),
            static::getContainer()->get(McpToolbox::class)->getTools(),
        );

        $this->assertContains('set_vehicle_facts', $names);
        $this->assertContains('remove_vehicle_facts', $names);
    }

    public function testWrittenFactsAreReturnedAndReadBack(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();

        $result = $this->tool($client, $sessionId, 'set_vehicle_facts', [
            'vehicle' => $iri,
            'facts' => [
                ['key' => 'paint_code', 'value' => '41W', 'source' => 'user'],
                ['key' => 'oil_capacity', 'value' => '4.3', 'unit' => 'L', 'source' => 'web', 'url' => 'https://example.com/spec'],
            ],
        ], 3);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($iri, $result['vehicle']['@id']);
        $this->assertSame(['paint_code', 'oil_capacity'], array_column($result['facts'], 'key'));
        $this->assertSame('L', $result['facts'][1]['unit']);
        $this->assertSame('https://example.com/spec', $result['facts'][1]['url']);
        $this->assertFalse($result['facts'][1]['verified']);

        $read = $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['paint_code', 'oil_capacity']], 4);
        $byKey = array_column($read['facts'], null, 'key');
        $this->assertSame('41W', $byKey['paint_code']['value']);
        $this->assertSame('4.3', $byKey['oil_capacity']['value']);
    }

    public function testWritingAnExistingKeyReplacesIt(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();

        $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']]], 3);
        $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => [['key' => 'oil_type', 'value' => 'SAE 5W-40', 'source' => 'web']]], 4);

        $facts = $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['oil_type']], 5)['facts'];
        $this->assertCount(1, $facts);
        $this->assertSame('SAE 5W-40', $facts[0]['value']);
        $this->assertSame('web', $facts[0]['source']);
    }

    public function testReplacingATaskAttributeTombstonesTheOldOne(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();
        $dm = static::getContainer()->get(DocumentManager::class);

        $this->tool($client, $sessionId, 'plan_maintenance', ['vehicle' => $iri, 'type' => 'oil_change'], 3);
        $dm->clear();
        $task = $dm->getRepository(PlannedMaintenance::class)->findOneBy(['type' => 'oil_change']);
        $task->setAttribute(VehicleFact::fromSource('oil_type', 'legacy', 'llm'));
        $dm->flush();
        $dm->clear();

        $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => [['key' => 'oil_type', 'value' => 'fresh', 'source' => 'llm']]], 4);

        $dm->clear();
        $task = $dm->getRepository(PlannedMaintenance::class)->findOneBy(['type' => 'oil_change']);
        $this->assertTrue($task->attributeByKey('oil_type')->isDeleted(), 'the superseded value must be soft-deleted, not left to fight the new one');
        $facts = $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['oil_type']], 5)['facts'];
        $this->assertSame('fresh', $facts[0]['value']);
    }

    public function testAnUnverifiedWriteNeverDisplacesABillPartFact(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();
        $this->tool($client, $sessionId, 'log_maintenance', [
            'vehicle' => $iri,
            'items' => [['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]]],
            'performedAt' => '2026-06-15',
        ], 3);

        $result = $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => [['key' => 'oil_filter_ref', 'value' => 'GUESS 1', 'source' => 'llm']]], 4);

        $this->assertSame(['oil_filter_ref'], $result['shadowed']);
        $after = $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['oil_filter_ref']], 5)['facts'];
        $this->assertSame('log', $after[0]['source']);
        $this->assertSame('MANN-FILTER W712/73', $after[0]['value']);
    }

    /**
     * @param array<string, mixed> $fact
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFacts')]
    public function testInvalidInputIsAStructuredResultAndWritesNothing(array $fact): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();

        $result = $this->tool($client, $sessionId, 'set_vehicle_facts', [
            'vehicle' => $iri,
            'facts' => [['key' => 'paint_code', 'value' => '41W', 'source' => 'user'], $fact],
        ], 3);

        $this->assertSame('invalid_input', $result['error']);
        $this->assertNotEmpty($result['message']);
        $this->assertSame([], $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['paint_code']], 4)['facts']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidFacts(): iterable
    {
        yield 'camelCase key' => [['key' => 'paintCode', 'value' => 'x', 'source' => 'user']];
        yield 'blank value' => [['key' => 'tyre_size', 'value' => '  ', 'source' => 'user']];
        yield 'forged authoritative source' => [['key' => 'tyre_size', 'value' => '205/55R16', 'source' => 'partsapi']];
        yield 'bad url' => [['key' => 'tyre_size', 'value' => '205/55R16', 'source' => 'web', 'url' => 'not a url']];
        // FILTER_VALIDATE_URL accepts these; the fact link is rendered as an href.
        yield 'javascript url' => [['key' => 'tyre_size', 'value' => '205/55R16', 'source' => 'web', 'url' => 'javascript://x%0Aalert(1)']];
        yield 'non-web scheme' => [['key' => 'tyre_size', 'value' => '205/55R16', 'source' => 'web', 'url' => 'ftp://example.com/spec']];
    }

    public function testEmptyFactsIsInvalidInput(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();

        $this->assertSame('invalid_input', $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => []], 3)['error']);
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->tool($client, $sessionId, 'set_vehicle_facts', [
            'vehicle' => '/garage/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ',
            'facts' => [['key' => 'paint_code', 'value' => '41W', 'source' => 'user']],
        ], 2);

        $this->assertContains($result['error'], ['not_found', 'invalid_input']);
    }

    public function testAnotherOwnersVehicleIsNotWritable(): void
    {
        static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);
        $owner = static::getContainer()->get(OwnerContext::class);

        $owner->set('alice@example.com');
        $iri = $toolbox->execute(new ToolCall('a', 'update_vehicle', ['make' => 'MAZDA', 'model' => 'MAZDA6']))->getResult()['vehicle']['@id'];

        $owner->set('bob@example.com');
        $result = $toolbox->execute(new ToolCall('b', 'set_vehicle_facts', [
            'vehicle' => $iri,
            'facts' => [['key' => 'paint_code', 'value' => '41W', 'source' => 'user']],
        ]))->getResult();
        $this->assertTrue($result->isError);
        $this->assertStringStartsWith('not_found: ', $result->content[0]->text);

        $owner->set('alice@example.com');
        $this->assertSame([], $toolbox->execute(new ToolCall('c', 'get_vehicle_facts', ['vehicle' => $iri, 'keys' => ['paint_code']]))->getResult()['facts']);
    }

    public function testRemoveSoftDeletesAndReportsUnknownKeys(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();
        $this->tool($client, $sessionId, 'set_vehicle_facts', ['vehicle' => $iri, 'facts' => [
            ['key' => 'paint_code', 'value' => '41W', 'source' => 'user'],
            ['key' => 'tyre_size', 'value' => '205/55R16', 'source' => 'user'],
        ]], 3);

        $result = $this->tool($client, $sessionId, 'remove_vehicle_facts', ['vehicle' => $iri, 'keys' => ['paint_code', 'nope']], 4);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['paint_code'], $result['removed']);
        $this->assertSame(['nope'], $result['notFound']);
        $facts = $this->tool($client, $sessionId, 'get_vehicle_facts', ['vehicle' => $iri], 5)['facts'];
        $this->assertSame(['tyre_size'], array_column($facts, 'key'));

        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();
        $carrier = $dm->getRepository(PlannedMaintenance::class)->findOneBy(['type' => 'user_facts']);
        $this->assertTrue($carrier->attributeByKey('paint_code')->isDeleted(), 'removal is a tombstone, the document survives');
    }

    public function testRemoveWithNoKeysIsInvalidInput(): void
    {
        [$client, $sessionId, $iri] = $this->clientWithVehicle();

        $this->assertSame('invalid_input', $this->tool($client, $sessionId, 'remove_vehicle_facts', ['vehicle' => $iri, 'keys' => []], 3)['error']);
    }
}
