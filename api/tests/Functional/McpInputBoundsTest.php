<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class McpInputBoundsTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function outOfBounds(): iterable
    {
        yield 'list_vehicles limit' => ['list_vehicles', ['limit' => 51]];
        yield 'search_documentation limit' => ['search_documentation', ['query' => 'vidange', 'limit' => 500]];
        yield 'get_maintenance_history limit' => ['get_maintenance_history', ['make' => 'MAZDA', 'model' => 'MAZDA6', 'limit' => 0]];
        yield 'list_due_maintenance limit' => ['list_due_maintenance', ['limit' => 1000]];
        yield 'toast message' => ['toast', ['message' => str_repeat('a', 2001)]];
        yield 'log_maintenance notes' => ['log_maintenance', ['make' => 'MAZDA', 'model' => 'MAZDA6', 'items' => [['type' => 'oil_change']], 'notes' => str_repeat('a', 2001)]];
        yield 'plan_maintenance parts' => ['plan_maintenance', ['make' => 'MAZDA', 'model' => 'MAZDA6', 'type' => 'brakes', 'parts' => array_fill(0, 51, ['ref' => 'X'])]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('outOfBounds')]
    public function testAnOutOfBoundsArgumentIsInvalidInput(string $tool, array $arguments): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => $tool, 'arguments' => $arguments], 3));

        $this->assertSame('invalid_input', $outcome['error'] ?? null, json_encode($outcome, \JSON_THROW_ON_ERROR));
    }

    public function testAnOversizedFactValueIsA422OverHttp(): void
    {
        $client = static::createClient();
        $created = $client->request('POST', '/garage/vehicles', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['make' => 'MAZDA', 'model' => 'MAZDA6', 'vehicleType' => 'car'],
        ])->toArray(false);

        $client->request('POST', $created['@id'].'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'oil_type', 'value' => str_repeat('a', 2001)],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
