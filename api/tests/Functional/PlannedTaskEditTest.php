<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlannedTaskEditTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @return array{client: \ApiPlatform\Test\Client, taskIri: string}
     */
    private function planTask(array $arguments): array
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => array_merge([
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
            ], $arguments),
        ], 3)['result']['structuredContent'];

        return ['client' => $client, 'taskIri' => $plan['task']['@id']];
    }

    public function testPatchEditsTheTaskIdentityFields(): void
    {
        ['client' => $client, 'taskIri' => $taskIri] = $this->planTask([
            'type' => 'brakes',
            'label' => 'plaquettes avant',
        ]);

        $patched = $client->request('PATCH', $taskIri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'type' => 'brake_pads',
                'label' => 'plaquettes arrière',
                'target' => ['value' => 90000, 'unit' => 'km'],
                'dueDate' => '2027-03-01',
            ],
        ])->toArray();

        $this->assertSame('brake_pads', $patched['type']);
        $this->assertSame('plaquettes arrière', $patched['label']);
        $this->assertSame(90000, $patched['target']['value']);
        $this->assertSame('km', $patched['target']['unit']);
        $this->assertSame('2027-03-01', $patched['dueDate']);
        $this->assertSame('open', $patched['status'], 'editing fields must not touch the lifecycle');
    }

    public function testEachPartCarriesAStableIdentifier(): void
    {
        ['client' => $client, 'taskIri' => $taskIri] = $this->planTask([
            'type' => 'oil_filter',
            'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile']],
        ]);

        $task = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertNotEmpty($task['parts'][0]['id'] ?? null, 'a stored part must expose its own ULID identifier');
    }

    public function testPartCanBeAddedThroughTheSubresource(): void
    {
        ['client' => $client, 'taskIri' => $taskIri] = $this->planTask([
            'type' => 'oil_filter',
            'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile']],
        ]);

        $created = $client->request('POST', $taskIri.'/parts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['ref' => 'F 026 407 085', 'brand' => 'BOSCH', 'label' => 'Filtre à huile', 'qty' => 1],
        ])->toArray();

        $this->assertNotEmpty($created['id'] ?? null, 'the added part comes back with its own id');
        $this->assertSame('F 026 407 085', $created['ref']);

        $task = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $refs = array_column($task['parts'], 'ref');
        $this->assertContains('W 712/73', $refs, 'the existing part survives');
        $this->assertContains('F 026 407 085', $refs, 'the new part is appended');
    }

    public function testPartCanBeEditedThroughTheSubresource(): void
    {
        ['client' => $client, 'taskIri' => $taskIri] = $this->planTask([
            'type' => 'oil_filter',
            'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile', 'qty' => 1]],
        ]);

        $task = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $partIri = $task['parts'][0]['@id'] ?? ($taskIri.'/parts/'.$task['parts'][0]['id']);

        $patched = $client->request('PATCH', $partIri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['qty' => 2, 'brand' => 'PURFLUX'],
        ])->toArray();

        $this->assertSame(2, $patched['qty']);
        $this->assertSame('PURFLUX', $patched['brand']);
        $this->assertSame('W 712/73', $patched['ref'], 'untouched fields survive the merge');
    }

    public function testPartCanBeRemovedThroughTheSubresource(): void
    {
        ['client' => $client, 'taskIri' => $taskIri] = $this->planTask([
            'type' => 'oil_filter',
            'parts' => [
                ['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile'],
                ['ref' => 'F 026 407 085', 'brand' => 'BOSCH', 'label' => 'Filtre à huile'],
            ],
        ]);

        $task = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $byRef = [];
        foreach ($task['parts'] as $part) {
            $byRef[$part['ref']] = $part['id'];
        }

        $client->request('DELETE', $taskIri.'/parts/'.$byRef['W 712/73'], [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $after = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $refs = array_column($after['parts'], 'ref');
        $this->assertNotContains('W 712/73', $refs, 'the removed part is gone');
        $this->assertContains('F 026 407 085', $refs, 'the other part stays');
    }
}
