<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\DashboardChannel;
use PHPUnit\Framework\TestCase;

final class DashboardChannelTest extends TestCase
{
    public function testABoundSessionTopicIsThePublishTopic(): void
    {
        $channel = new DashboardChannel();
        $channel->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('https://localhost/agent/sessions/abc', $channel->topic());
        $this->assertSame('abc', $channel->sessionId());
    }

    public function testNoSessionMeansNoTopicAndNoSessionId(): void
    {
        $channel = new DashboardChannel();

        $this->assertNull($channel->topic());
        $this->assertNull($channel->sessionId());
    }

    public function testBindingNullClearsTheSession(): void
    {
        $channel = new DashboardChannel();
        $channel->bindSession('https://localhost/agent/sessions/abc');
        $channel->bindSession(null);

        $this->assertNull($channel->topic());
    }
}
