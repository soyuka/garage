<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\RateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class RateLimitExceededExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::set(new Clock());
    }

    public function testRetryAfterIsCountedFromTheClock(): void
    {
        Clock::set(new MockClock('2030-01-01 00:00:00'));

        $this->assertSame(90, (new RateLimitExceededException(new \DateTimeImmutable('2030-01-01 00:01:30')))->retryAfterSeconds());
    }
}
