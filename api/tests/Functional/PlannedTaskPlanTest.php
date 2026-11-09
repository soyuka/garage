<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The finder's "add to my garage": POST /garage/planned-tasks is the REST face of plan_maintenance —
 * same planner and vehicle dedup, HTTP status semantics instead of structured tool results.
 */
final class PlannedTaskPlanTest extends AppTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'make' => 'MAZDA',
            'model' => 'MAZDA6',
            'carId' => 12345,
            'plate' => 'AB-123-CD',
            'type' => 'oil_filter',
            'label' => 'Filtre à huile',
            'source' => 'user',
            'addedBy' => 'finder',
            'parts' => [
                ['ref' => 'LF05-14-302A', 'brand' => 'MAZDA', 'label' => 'Filtre à huile'],
                ['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'];
    }

    public function testPostCreatesVehicleAndTaskAndReturnsTheVehicleIri(): void
    {
        $client = static::createClient();

        $data = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => self::payload()])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $data['vehicle']);
        $this->assertStringStartsWith('/garage/planned-tasks/', $data['@id']);
        $this->assertSame('oil_filter', $data['type']);
        $this->assertSame('open', $data['status']);
        $this->assertSame(['LF05-14-302A', 'W 712/73'], array_column($data['parts'], 'ref'));

        $vehicle = $client->request('GET', $data['vehicle'], ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertSame('AB-123-CD', $vehicle['plate']);
    }

    public function testResendingUpsertsTheSameTaskOnTheSameVehicle(): void
    {
        $client = static::createClient();

        $first = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => self::payload()])->toArray();
        $second = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => self::payload()])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($first['vehicle'], $second['vehicle']);
        $this->assertSame($first['@id'], $second['@id']);

        $members = $client->request('GET', $first['vehicle'].'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertCount(1, $members);
        $this->assertCount(2, $members[0]['parts']);
    }

    public function testRePostingWithTheChosenVehicleIriPlansOnThatRow(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $chosen = new Vehicle('PEUGEOT', '307', VehicleType::Car, 'user');
        $chosen->setOwner(self::TEST_OWNER);
        $dm->persist($chosen);
        $dm->flush();

        $data = $client->request('POST', '/garage/planned-tasks', [
            'headers' => self::headers(),
            'json' => ['vehicle' => '/garage/vehicles/'.(string) $chosen->getId(), 'type' => 'other', 'label' => 'Rotule axiale', 'parts' => [['ref' => 'JAR123', 'brand' => 'TRW']]],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/garage/vehicles/'.(string) $chosen->getId(), $data['vehicle']);
    }

    public function testFindRouteIsStoredReturnedAndReplacedByTheLatestFind(): void
    {
        $client = static::createClient();
        $first = '/p/XE304ZE/c/100538/g/247/r/c2/01M5QBGXRK7S728KVD7R4VBHY8';
        $second = '/k/8050-PC/c/100538/v/c1';
        $payload = self::payload();
        $payload['parts'] = [['ref' => 'LF05-14-302A', 'brand' => 'MAZDA', 'findRoute' => $first]];

        $data = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => $payload])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($first, $data['parts'][0]['findRoute']);

        $payload['parts'] = [['ref' => 'LF05-14-302A']];
        $data = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => $payload])->toArray();
        $this->assertSame($first, $data['parts'][0]['findRoute']);

        $payload['parts'] = [['ref' => 'LF05-14-302A', 'findRoute' => $second]];
        $data = $client->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => $payload])->toArray();
        $this->assertCount(1, $data['parts']);
        $this->assertSame($second, $data['parts'][0]['findRoute']);

        $members = $client->request('GET', $data['vehicle'].'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertSame($second, $members[0]['parts'][0]['findRoute']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidFindRoutes(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'absolute url' => ['//evil.example/p/XE304ZE/c/1'];
        yield 'leading hash' => ['#/p/XE304ZE/c/100538'];
        yield 'unknown view' => ['/x/XE304ZE/c/100538'];
        yield 'too long' => ['/p/XE304ZE/c/100538/r/'.str_repeat('a', 200)];
        yield 'not a string' => [['/p/XE304ZE/c/1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFindRoutes')]
    public function testInvalidFindRouteIs422(mixed $findRoute): void
    {
        $payload = self::payload();
        $payload['parts'] = [['ref' => 'LF05-14-302A', 'findRoute' => $findRoute]];

        static::createClient()->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => $payload]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testAnonymousPostIsRejected(): void
    {
        static::createAnonymousClient()->request('POST', '/garage/planned-tasks', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => self::payload(),
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMissingVehicleAndIdentityIs422(): void
    {
        $payload = self::payload();
        unset($payload['make'], $payload['model']);

        static::createClient()->request('POST', '/garage/planned-tasks', ['headers' => self::headers(), 'json' => $payload]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testHalfATargetPairIs422(): void
    {
        static::createClient()->request('POST', '/garage/planned-tasks', [
            'headers' => self::headers(),
            'json' => self::payload() + ['targetUsageValue' => 160000],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnknownVehicleIriIs404(): void
    {
        static::createClient()->request('POST', '/garage/planned-tasks', [
            'headers' => self::headers(),
            'json' => ['vehicle' => '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV', 'type' => 'oil_filter'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNearMatchIs409WithCandidatesAndCreatesNothing(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $existing = new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'user');
        $existing->setOwner(self::TEST_OWNER);
        $dm->persist($existing);
        $dm->flush();
        $dm->clear();

        $response = $client->request('POST', '/garage/planned-tasks', [
            'headers' => self::headers(),
            'json' => ['make' => 'Wheel Horse', 'model' => '246-H', 'vehicleType' => 'groundscare', 'type' => 'oil_change'],
        ]);

        $this->assertResponseStatusCodeSame(409);
        $body = $response->toArray(false);
        $this->assertSame('ambiguous_match', $body['status']);
        $this->assertCount(1, $body['candidates']);
        $this->assertSame('TORO', $body['candidates'][0]['make']);
        $this->assertSame('/garage/vehicles/'.(string) $existing->getId(), $body['candidates'][0]['@id']);

        $list = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertCount(1, $list);
    }
}
