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

        $this->assertJson($encoded['body']);
        $this->assertStringNotContainsString('O:', $encoded['body']);
    }

    public function testQueuedTurnRoundTrips(): void
    {
        $serializer = $this->serializer();
        $turn = $this->turn();

        $this->assertEquals($turn, $serializer->decode($serializer->encode(new Envelope($turn)))->getMessage());
    }

    public function testNoCredentialIsQueued(): void
    {
        $body = $this->serializer()->encode(new Envelope($this->turn()))['body'];

        $this->assertStringNotContainsStringIgnoringCase('token', $body);
    }

    public function testATurnQueuedWithTheRetiredAccessTokenStillDecodes(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->turn()));
        $legacy = json_decode($encoded['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($legacy);
        // @mago-expect lint:no-literal-password test fixture
        $legacy['accessToken'] = 'token.value';
        $encoded['body'] = json_encode($legacy, \JSON_THROW_ON_ERROR);

        $this->assertEquals($this->turn(), $serializer->decode($encoded)->getMessage());
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

        $this->assertEquals($this->turn(), $decoded->getMessage());
        $this->assertSame(2, $decoded->last(RedeliveryStamp::class)?->getRetryCount());
        $this->assertSame('async', $decoded->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        $this->assertSame('upstream timed out', $decoded->last(ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    private function serializer(): SerializerInterface
    {
        $serializer = static::getContainer()->get('messenger.default_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
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
        );
    }
}
