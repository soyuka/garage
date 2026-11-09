<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

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
        $encoded = $this->serializer()->encode(new Envelope($this->turn()));

        self::assertJson($encoded['body']);
        self::assertStringNotContainsString('O:', $encoded['body']);
    }

    public function testQueuedTurnRoundTrips(): void
    {
        $serializer = $this->serializer();
        $turn = $this->turn();

        self::assertEquals($turn, $serializer->decode($serializer->encode(new Envelope($turn)))->getMessage());
    }

    public function testFailedTurnKeepsItsStamps(): void
    {
        $serializer = $this->serializer();
        $envelope = new Envelope($this->turn(), [
            new RedeliveryStamp(2),
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new \RuntimeException('upstream timed out')),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        self::assertEquals($this->turn(), $decoded->getMessage());
        self::assertSame(2, $decoded->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('async', $decoded->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame('upstream timed out', $decoded->last(ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    private function serializer(): SerializerInterface
    {
        return static::getContainer()->get('messenger.default_serializer');
    }

    private function turn(): RunAgentTurn
    {
        return new RunAgentTurn(
            instruction: 'vidange faite à 152 000 km',
            sessionTopic: 'https://localhost/agent/sessions/abc',
            owner: 'owner@example.com',
            selectedId: '/garage/vehicles/42',
            summary: 'Espace IV 2.0 16V',
            pendingContext: 'Quelle motorisation ?',
            locale: 'en',
            accessToken: 'token.value',
        );
    }
}
