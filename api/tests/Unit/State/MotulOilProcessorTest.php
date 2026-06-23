<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use App\Dto\MotulOilInput;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\Garage\VehicleType;
use App\Motul\MotulClient;
use App\Motul\MotulOilAdvisor;
use App\Motul\MotulRecommendationParser;
use App\Siv\SivClient;
use App\State\MotulOilProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Ulid;

/**
 * `get_motul_oil` addressed by a vehicle @id (option A): the variant must be pinned from data the
 * garage already holds — make/model + year from the row, engine from the stored plate's SIV record —
 * so the model cannot starve the Motul query by omitting them (which left it perpetually "ambiguous"
 * and never reaching a grade). With the engine derived, the advisor narrows to one variant and matches.
 */
final class MotulOilProcessorTest extends TestCase
{
    private const FIX = __DIR__.'/../../Fixtures/motul/';

    public function testVehicleIdDerivesYearAndEngineThenMatches(): void
    {
        $advisor = new MotulOilAdvisor(new MotulClient(
            new MockHttpClient(static fn (string $m, string $url): MockResponse => new MockResponse(
                str_contains($url, 'search-vehicle')
                    ? file_get_contents(self::FIX.'search-vehicle_mazda6.json')
                    : file_get_contents(self::FIX.'recommendations_9950.html'),
            )),
            new ArrayAdapter(),
            new MotulRecommendationParser(),
        ));

        // SIV record for the plate carries the engine displacement the Motul query needs.
        $siv = new SivClient(
            new MockHttpClient(new MockResponse(json_encode([
                'data' => ['marque' => 'MAZDA', 'modele' => '6', 'capacite_litres' => '1.8', 'version' => '1.8 MZR'],
                'code_erreur' => 200,
            ], \JSON_THROW_ON_ERROR))),
            'siv-token',
            new ArrayAdapter(),
        );

        $vehicle = new Vehicle('MAZDA', '6', VehicleType::Car, 'siv', year: 2004, plate: 'AA-123-BB');

        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturn(
            new class($vehicle->getId()) {
                public function __construct(public readonly Ulid $id)
                {
                }
            },
        );

        $processor = new MotulOilProcessor($advisor, $iriConverter, $resolver, $siv);

        $input = new MotulOilInput();
        $input->vehicle = '/garage/vehicles/'.$vehicle->getId();

        $result = $processor->process($input, $this->createStub(Operation::class));

        $this->assertSame('matched', $result->structuredContent['status']);
        $this->assertSame(['5W-30'], $result->structuredContent['engineOilGrades']);
        $this->assertStringContainsString('recommendations/9950', $result->structuredContent['sourceUrl']);
    }

    public function testMotulIdPinsVariantWithoutSearchOrSiv(): void
    {
        // A `motulId` (carried from an ambiguous turn's candidate) pins the exact Motul row: no
        // search-vehicle, no SIV, no @id resolution — the second turn converges instead of looping.
        $advisor = new MotulOilAdvisor(new MotulClient(
            new MockHttpClient(static function (string $m, string $url): MockResponse {
                if (str_contains($url, 'search-vehicle')) {
                    throw new \LogicException('motulId path must not search.');
                }

                return new MockResponse(file_get_contents(self::FIX.'recommendations_9950.html'));
            }),
            new ArrayAdapter(),
            new MotulRecommendationParser(),
        ));

        $siv = new SivClient(new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('motulId path must not hit SIV.');
        }), 'siv-token', new ArrayAdapter());

        $processor = new MotulOilProcessor(
            $advisor,
            $this->createStub(IriConverterInterface::class),
            $this->createStub(GarageResolver::class),
            $siv,
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
            new ArrayAdapter(),
            new MotulRecommendationParser(),
        ));

        // SIV would yield a displacement that pins NOTHING — proving the stored modification wins.
        $siv = new SivClient(
            new MockHttpClient(new MockResponse(json_encode([
                'data' => ['marque' => 'MAZDA', 'modele' => '6', 'capacite_litres' => '9.9'],
                'code_erreur' => 200,
            ], \JSON_THROW_ON_ERROR))),
            'siv-token',
            new ArrayAdapter(),
        );

        $vehicle = new Vehicle('MAZDA', '6', VehicleType::Car, 'siv', year: 2004, plate: 'AA-123-BB', engineVariant: '1.8');

        $resolver = $this->createStub(GarageResolver::class);
        $resolver->method('resolveById')->willReturn($vehicle);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturn(
            new class($vehicle->getId()) {
                public function __construct(public readonly Ulid $id)
                {
                }
            },
        );

        $processor = new MotulOilProcessor($advisor, $iriConverter, $resolver, $siv);

        $input = new MotulOilInput();
        $input->vehicle = '/garage/vehicles/'.$vehicle->getId();

        $result = $processor->process($input, $this->createStub(Operation::class));

        $this->assertSame('matched', $result->structuredContent['status']);
        $this->assertSame(['5W-30'], $result->structuredContent['engineOilGrades']);
    }
}
