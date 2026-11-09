<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderIntent;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Finder\PartPhotoHints;
use App\Agent\TurnSurface;
use App\Catalog\CarType;
use App\Message\RunAgentTurn;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class RunAgentTurnTransportSerializationTest extends KernelTestCase
{
    public function testQueuedTurnTravelsAsJson(): void
    {
        $encoded = $this->serializer()->encode(new Envelope($this->fullTurn()));

        self::assertJson($encoded['body']);
        self::assertStringNotContainsString('O:', $encoded['body']);
    }

    public function testFullTurnRoundTrips(): void
    {
        $serializer = $this->serializer();
        $turn = $this->fullTurn();

        self::assertEquals($turn, $serializer->decode($serializer->encode(new Envelope($turn)))->getMessage());
    }

    public function testBareTurnRoundTrips(): void
    {
        $serializer = $this->serializer();
        $turn = new RunAgentTurn(instruction: 'filtre à huile', sessionTopic: 'https://localhost/agent/sessions/abc', owner: 'owner@example.com');

        self::assertEquals($turn, $serializer->decode($serializer->encode(new Envelope($turn)))->getMessage());
    }

    public function testFailedTurnKeepsItsStamps(): void
    {
        $serializer = $this->serializer();
        $envelope = new Envelope($this->fullTurn(), [
            new RedeliveryStamp(2),
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new \RuntimeException('upstream timed out')),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        self::assertEquals($this->fullTurn(), $decoded->getMessage());
        self::assertSame(2, $decoded->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('async', $decoded->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame('upstream timed out', $decoded->last(ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    private function serializer(): SerializerInterface
    {
        return static::getContainer()->get('messenger.default_serializer');
    }

    private function fullTurn(): RunAgentTurn
    {
        return new RunAgentTurn(
            instruction: 'quelle référence ?',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'owner@example.com',
            summary: 'Filtre à huile MANN W 712/75',
            pendingContext: 'Quelle motorisation ?',
            locale: 'en',
            surface: TurnSurface::Finder,
            vehicle: new FinderVehicle(carId: 12345, carType: CarType::PassengerCar, make: 'RENAULT', model: 'ESPACE IV', engine: '2.0 16V', plate: 'AB-123-CD', organisationNumber: '10734'),
            intent: FinderIntent::Photo,
            photoHints: new PartPhotoHints(familyGuess: 'oil filter', printedReferences: ['W 712/75', 'HU 711/51'], brandGuess: 'MANN'),
            imageStash: 'stash-key',
            images: [['mimeType' => 'image/jpeg', 'bytes' => 123456], ['mimeType' => 'image/png', 'bytes' => 42]],
            buildLabel: new BuildLabelHints(opr: '10734', printedCodes: ['F4R', 'JK0']),
            turnId: '01JTURNID',
        );
    }
}
