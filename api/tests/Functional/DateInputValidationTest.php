<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\Attributes\DataProvider;

final class DateInputValidationTest extends AppTestCase
{
    use McpClientTrait;

    private const string GARBAGE = 'next tuesday-ish';

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function mcpCalls(): iterable
    {
        yield 'plan_maintenance dueDate' => ['plan_maintenance', ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'confirmNew' => true, 'type' => 'brakes', 'dueDate' => self::GARBAGE]];
        yield 'plan_maintenance relative dueDate' => ['plan_maintenance', ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'confirmNew' => true, 'type' => 'brakes', 'dueDate' => 'tomorrow']];
        yield 'log_maintenance performedAt' => ['log_maintenance', ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'confirmNew' => true, 'items' => [['type' => 'oil_change']], 'performedAt' => self::GARBAGE]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('mcpCalls')]
    public function testAGarbageDateIsAnInvalidInputToolError(string $tool, array $arguments): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => $tool, 'arguments' => $arguments], 3));

        $this->assertSame('invalid_input', $outcome['error'] ?? null, json_encode($outcome, \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('Fix these arguments and call again.', $outcome['message']);
        $this->assertSame(0, static::getContainer()->get(DocumentManager::class)->getRepository(Vehicle::class)->createQueryBuilder()->count()->getQuery()->execute());
    }

    public function testAValidDateStillPlans(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'plan_maintenance', 'arguments' => [
            'make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'confirmNew' => true, 'type' => 'brakes', 'dueDate' => '2027-03-01',
        ]], 3));

        $this->assertSame('2027-03-01', $outcome['task']['dueDate'] ?? null, json_encode($outcome, \JSON_THROW_ON_ERROR));
    }

    public function testHttpCreateRejectsAGarbageDueDate(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/planned-tasks', [
            'headers' => self::jsonLd(),
            'json' => ['type' => 'brakes', 'dueDate' => self::GARBAGE],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testHttpPlanRejectsAGarbageDueDate(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/planned-tasks', [
            'headers' => self::jsonLd(),
            'json' => ['make' => 'MAZDA', 'model' => 'MAZDA6', 'type' => 'oil_filter', 'dueDate' => self::GARBAGE],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testHttpPatchRejectsAGarbageDueDate(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();
        $task = $client->request('POST', $iri.'/planned-tasks', ['headers' => self::jsonLd(), 'json' => ['type' => 'brakes']])->toArray();

        $client->request('PATCH', $task['@id'], [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['dueDate' => self::GARBAGE],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testHttpRecordRejectsARelativePerformedAt(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::jsonLd(),
            'json' => ['performedAt' => 'yesterday', 'items' => [['type' => 'oil_change']]],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, string>
     */
    private static function jsonLd(): array
    {
        return self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'];
    }

    private function seedVehicleIri(): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return '/garage/vehicles/'.$vehicle->getId();
    }
}
