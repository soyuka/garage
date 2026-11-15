<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use App\ApiResource\GarageVehicle;
use App\Bonneref\BonnerefClient;
use App\Bonneref\BonnerefServiceToken;
use App\Dto\MotulOilInput;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleType;
use App\Motul\MotulClient;
use App\Motul\MotulOilAdvisor;
use App\Motul\MotulRecommendationParser;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use App\Security\OwnerRateLimit;
use App\State\MotulOilProcessor;
use App\Tests\Mock\BonnerefHttpClient;
use App\Tests\Support\InMemoryMotulResponseStore;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * `get_motul_oil` addressed by a vehicle @id (option A): the variant must be pinned from data the
 * garage already holds — make/model + year from the row, engine from the stored plate's registry record —
 * so the model cannot starve the Motul query by omitting them (which left it perpetually "ambiguous"
 * and never reaching a grade). With the engine derived, the advisor narrows to one variant and matches.
 */
final class MotulOilProcessorTest extends TestCase
{
    private const FIX = __DIR__.'/../../Fixtures/motul/';

    protected function setUp(): void
    {
        BonnerefHttpClient::reset();
    }

    public function testVehicleIdDerivesYearAndEngineThenMatches(): void
    {
        $advisor = new MotulOilAdvisor(new MotulClient(
            new MockHttpClient(static fn (string $m, string $url): MockResponse => new MockResponse(
                str_contains($url, 'search-vehicle')
                    ? file_get_contents(self::FIX.'search-vehicle_mazda6.json')
                    : file_get_contents(self::FIX.'recommendations_9950.html'),
            )),
            new InMemoryMotulResponseStore(),
            new MotulRecommendationParser(),
        ));

        // The registry record for the plate carries the engine displacement the Motul query needs.
        $bonneref = self::bonneref((new BonnerefHttpClient())->route('/vehicles/AA123BB', json_encode([
            'plate' => 'AA123BB', 'make' => 'MAZDA', 'model' => '6', 'engineCapacityCcm' => 1798, 'version' => '1.8 MZR',
        ], \JSON_THROW_ON_ERROR)));

        $vehicle = new Vehicle('MAZDA', '6', VehicleType::Car, 'siv', year: 2004, plate: 'AA-123-BB');

        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturn(GarageVehicle::fromEntity($vehicle));

        $processor = new MotulOilProcessor($advisor, new VehicleIriResolver($iriConverter, $this->createStub(VehicleRepository::class)), $resolver, $bonneref, ...self::unlimited());

        $input = new MotulOilInput();
        $input->vehicle = '/garage/vehicles/'.(string) $vehicle->getId();

        $result = $processor->process($input, $this->createStub(Operation::class));

        $this->assertSame('matched', $result->structuredContent['status']);
        $this->assertSame(['5W-30'], $result->structuredContent['engineOilGrades']);
        $this->assertStringContainsString('recommendations/9950', $result->structuredContent['sourceUrl']);
    }

    public function testMotulIdPinsVariantWithoutSearchOrThePlateLookup(): void
    {
        // A `motulId` (carried from an ambiguous turn's candidate) pins the exact Motul row: no
        // search-vehicle, no registry lookup, no @id resolution — the second turn converges instead of looping.
        $advisor = new MotulOilAdvisor(new MotulClient(
            new MockHttpClient(static function (string $m, string $url): MockResponse {
                if (str_contains($url, 'search-vehicle')) {
                    throw new \LogicException('motulId path must not search.');
                }

                return new MockResponse(file_get_contents(self::FIX.'recommendations_9950.html'));
            }),
            new InMemoryMotulResponseStore(),
            new MotulRecommendationParser(),
        ));

        $bonneref = self::bonneref((new BonnerefHttpClient())->route('/vehicles/AA123BB', new \LogicException('motulId path must not hit bonneref.')));

        $processor = new MotulOilProcessor(
            $advisor,
            new VehicleIriResolver($this->createStub(IriConverterInterface::class), $this->createStub(VehicleRepository::class)),
            $this->createStub(GarageResolver::class),
            $bonneref,
            ...self::unlimited(),
        );

        $input = new MotulOilInput();
        $input->motulId = '9950';

        $result = $processor->process($input, $this->createStub(Operation::class));

        $this->assertSame('matched', $result->structuredContent['status']);
        $this->assertSame(['5W-30'], $result->structuredContent['engineOilGrades']);
    }

    public function testStoredModificationIsPreferredOverPlateEngine(): void
    {
        // The stored trim ("1.8") pins the variant; the plate's coarser displacement would not.
        $advisor = new MotulOilAdvisor(new MotulClient(
            new MockHttpClient(static fn (string $m, string $url): MockResponse => new MockResponse(
                str_contains($url, 'search-vehicle')
                    ? file_get_contents(self::FIX.'search-vehicle_mazda6.json')
                    : file_get_contents(self::FIX.'recommendations_9950.html'),
            )),
            new InMemoryMotulResponseStore(),
            new MotulRecommendationParser(),
        ));

        // The registry would yield a displacement that pins NOTHING — proving the stored modification wins.
        $bonneref = self::bonneref((new BonnerefHttpClient())->route('/vehicles/AA123BB', json_encode([
            'plate' => 'AA123BB', 'make' => 'MAZDA', 'model' => '6', 'engineCapacityCcm' => 9900,
        ], \JSON_THROW_ON_ERROR)));

        $vehicle = new Vehicle('MAZDA', '6', VehicleType::Car, 'siv', year: 2004, plate: 'AA-123-BB', engineVariant: '1.8');

        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturn(GarageVehicle::fromEntity($vehicle));

        $processor = new MotulOilProcessor($advisor, new VehicleIriResolver($iriConverter, $this->createStub(VehicleRepository::class)), $resolver, $bonneref, ...self::unlimited());

        $input = new MotulOilInput();
        $input->vehicle = '/garage/vehicles/'.(string) $vehicle->getId();

        $result = $processor->process($input, $this->createStub(Operation::class));

        $this->assertSame('matched', $result->structuredContent['status']);
        $this->assertSame(['5W-30'], $result->structuredContent['engineOilGrades']);
    }

    private static function bonneref(BonnerefHttpClient $http): BonnerefClient
    {
        $casdoor = new MockHttpClient(static fn (string $method, string $url): JsonMockResponse => str_ends_with($url, '/.well-known/openid-configuration')
            ? new JsonMockResponse(['token_endpoint' => 'https://casdoor.test/token'])
            // @mago-expect lint:no-literal-password test fixture
            : new JsonMockResponse(['access_token' => 'service-token', 'expires_in' => 604800]));
        $owner = new OwnerContext(new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => new TokenStorage()])), 'owner@example.com');

        return new BonnerefClient($http, new BonnerefServiceToken($casdoor, new ArrayAdapter(), 'https://casdoor.test', 'mpa-service-id', 'mpa-service-secret'), $owner);
    }

    /**
     * @return array{OwnerRateLimit, RateLimiterFactory}
     */
    private static function unlimited(): array
    {
        $owner = new OwnerContext(new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => new TokenStorage()])), 'owner@example.com');

        return [new OwnerRateLimit($owner), new RateLimiterFactory(['id' => 'motul', 'policy' => 'no_limit'], new InMemoryStorage())];
    }
}
