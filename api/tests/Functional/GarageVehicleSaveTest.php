<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Tests\Mock\RecordingHub;
use Doctrine\ODM\MongoDB\DocumentManager;

final class GarageVehicleSaveTest extends AppTestCase
{
    private const array PEUGEOT = ['make' => 'PEUGEOT', 'model' => '307', 'carId' => 9586, 'plate' => 'XB-301-ZB'];

    private const array REGISTRATION = [
        'vin' => 'VF33CRFJF4Y000001',
        'firstRegistration' => '2005-03-29',
        'cnit' => 'MPE0307N20E1',
        'displacement' => '1997',
        'powerKw' => '100',
        'energy' => 'GO',
        'fiscalPower' => '7',
    ];

    public function testCreatesTheVehicle(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT);

        $this->assertResponseStatusCodeSame(201);
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $body['@id']);
        $this->assertSame(['PEUGEOT', '307', 9586, 'XB-301-ZB'], [$body['make'], $body['model'], $body['kType'], $body['plate']]);
        $this->assertNotSame([], array_filter(static::getContainer()->get(RecordingHub::class)->updates, static fn ($u): bool => str_contains($u->getData(), '"kind":"vehicle"')));
    }

    public function testTheSamePlateMatchesTheStoredVehicle(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $second = $this->save($client, ['plate' => 'xb 301 zb'] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($first['@id'], $second['@id']);
        $this->assertCount(1, $this->garage($client));
    }

    public function testAnotherPlateOnTheSameModelIsAnotherCar(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $second = $this->save($client, ['plate' => 'GH-222-JK'] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotSame($first['@id'], $second['@id']);
        $this->assertCount(2, $this->garage($client));
    }

    public function testNoPlateAndAKnownCarIdIsAmbiguous(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $body = $this->save($client, ['make' => 'PEUGEOT', 'model' => '307 SW', 'carId' => 9586]);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('ambiguous_match', $body['status']);
        $this->assertIsString($body['reason']);
        $this->assertSame([$first['@id']], array_column($body['candidates'], '@id'));
        $this->assertCount(1, $this->garage($client));

        $this->save($client, ['make' => 'PEUGEOT', 'model' => '307 SW', 'carId' => 9586, 'confirmNew' => true]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testAVehicleIriEnrichesThatRow(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $stored = new Vehicle('PEUGEOT', '307', VehicleType::Car, 'user');
        $stored->setOwner(self::TEST_OWNER);
        $dm->persist($stored);
        $dm->flush();

        $body = $this->save($client, ['vehicle' => '/garage/vehicles/'.(string) $stored->getId()] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('/garage/vehicles/'.(string) $stored->getId(), $body['@id']);
        $this->assertSame([9586, 'XB-301-ZB'], [$body['kType'], $body['plate']]);
    }

    public function testAnUnknownVehicleIriIs404(): void
    {
        $client = static::createClient();

        $this->save($client, ['vehicle' => '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMakeAndModelAreRequiredWithoutAVehicle(): void
    {
        $client = static::createClient();

        $this->save($client, ['plate' => 'XB-301-ZB']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegistrationFactsLandOnTheVehicleSourcedFromTheCarteGrise(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT + ['registration' => self::REGISTRATION]);

        $this->assertResponseStatusCodeSame(201);
        $facts = $this->factsOf($client, $body['@id']);
        $this->assertSame(
            [
                'vin' => ['VF33CRFJF4Y000001', null],
                'first_registration_date' => ['2005-03-29', null],
                'cnit' => ['MPE0307N20E1', null],
                'displacement' => ['1997', 'cm³'],
                'power_kw' => ['100', 'kW'],
                'energy' => ['GO', null],
                'fiscal_power' => ['7', 'CV'],
            ],
            $facts,
        );
    }

    public function testTheBuildCodeIsDecodedIntoFactsSourcedFromTheCodeDeFabrication(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT + ['buildCode' => '982488', 'registration' => ['energy' => 'GO']]);

        $this->assertResponseStatusCodeSame(201);
        $facts = $this->factsOf($client, $body['@id'], ['organisation_number' => 'code de fabrication', 'build_date' => 'code de fabrication', 'energy' => 'carte grise']);
        $this->assertSame(['energy' => ['GO', null], 'organisation_number' => ['09824', null], 'build_date' => ['2003-10-02', null]], $facts);
    }

    public function testAnUnsupportedMakeOrAnInvalidBuildCodeWritesNothingAndStillSaves(): void
    {
        $client = static::createClient();

        $mazda = $this->save($client, ['make' => 'MAZDA', 'model' => '6', 'plate' => 'AB-123-CD', 'buildCode' => '982488']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([], $this->factsOf($client, $mazda['@id']));

        $peugeot = $this->save($client, self::PEUGEOT + ['buildCode' => 'zz']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([], $this->factsOf($client, $peugeot['@id']));
    }

    public function testOnlyTheReadRegistrationFieldsAreRecorded(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT + ['registration' => ['vin' => 'VF33CRFJF4Y000001', 'energy' => ' ']]);

        $this->assertSame(['vin' => ['VF33CRFJF4Y000001', null]], $this->factsOf($client, $body['@id']));
    }

    public function testSavingTheSameCarteGriseTwiceReusesTheVehicleAndDoesNotDuplicateFacts(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT + ['registration' => self::REGISTRATION]);

        $second = $this->save($client, ['plate' => 'xb 301 zb'] + self::PEUGEOT + ['registration' => self::REGISTRATION]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($first['@id'], $second['@id']);
        $this->assertCount(1, $this->garage($client));
        $this->assertCount(7, $this->factsOf($client, $first['@id']));
    }

    public function testAnotherReadReplacesTheFactValue(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT + ['registration' => self::REGISTRATION]);

        $this->save($client, self::PEUGEOT + ['registration' => ['fiscalPower' => '8']]);

        $this->assertSame(['8', 'CV'], $this->factsOf($client, $first['@id'])['fiscal_power']);
    }

    public function testASaveWithoutRegistrationWritesNoFact(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT);

        $this->assertSame([], $this->factsOf($client, $body['@id']));
    }

    public function testAnonymousIsRejected(): void
    {
        $client = static::createAnonymousClient();

        $client->request('POST', '/garage/vehicles/save', ['headers' => ['Content-Type' => 'application/ld+json'], 'json' => self::PEUGEOT]);

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function save(Client $client, array $payload): array
    {
        return $client->request('POST', '/garage/vehicles/save', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => $payload,
        ])->toArray(false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function garage(Client $client): array
    {
        return $client->request('GET', '/garage/vehicles', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
    }

    /**
     * @param array<string, string> $sources expected source per fact key, 'carte grise' otherwise
     *
     * @return array<array-key, array{mixed, mixed}> key => [value, unit]
     */
    private function factsOf(Client $client, string $iri, array $sources = []): array
    {
        $facts = [];
        $stored = $client->request('GET', $iri, ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray()['facts'];
        $this->assertIsArray($stored);
        foreach ($stored as $fact) {
            $this->assertSame($sources[$fact['key']] ?? 'carte grise', $fact['source']);
            $this->assertFalse($fact['verified']);
            $facts[$fact['key']] = [$fact['value'], $fact['unit']];
        }

        return $facts;
    }
}
