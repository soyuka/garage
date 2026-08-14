<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\DashboardChannel;
use App\Security\OwnerContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class DashboardChannelTest extends TestCase
{
    public function testABoundSessionTopicWins(): void
    {
        $channel = $this->channel('owner-1');
        $channel->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('https://localhost/agent/sessions/abc', $channel->topic());
        $this->assertSame('abc', $channel->sessionId());
    }

    public function testWithoutASessionItFallsBackToTheOwnerPilotTopic(): void
    {
        $channel = $this->channel('owner-1');

        $topic = $channel->topic();

        $this->assertNotNull($topic);
        $this->assertStringStartsWith('https://localhost/agent/pilots/', $topic);
        $this->assertNull($channel->sessionId(), 'the pilot topic is not a turn session');
    }

    /**
     * The hub allows anonymous subscribers (frankenphp/Caddyfile), so the topic is the only thing
     * standing between one owner's render intents and anybody else's EventSource. It must therefore
     * be unguessable from the owner id — hence the keyed digest rather than the raw `sub`.
     */
    public function testThePilotTopicIsOpaqueStableAndPerOwner(): void
    {
        $first = $this->channel('owner-1')->topic();

        $this->assertSame($first, $this->channel('owner-1')->topic(), 'same owner → same topic across processes');
        $this->assertNotSame($first, $this->channel('owner-2')->topic());
        $this->assertStringNotContainsString('owner-1', (string) $first);
    }

    public function testNoOwnerAndNoSessionMeansNoTopic(): void
    {
        $this->assertNull($this->channel(null)->topic());
    }

    private function channel(?string $owner): DashboardChannel
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $context = new OwnerContext($security);
        $context->set($owner);

        return new DashboardChannel($context, 'https://localhost', 'a-secret');
    }
}
